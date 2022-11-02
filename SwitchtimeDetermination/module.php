<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class SwitchtimeDetermination extends IPSModule
{
    use SwitchtimeDetermination\StubsCommonLib;
    use SwitchtimeDeterminationLocalLib;

    private static $semaphoreTM = 5 * 1000;

    public static $ident_raw_pfx = 'SWITCHTIME_';
    public static $ident_fmt_pfx = 'FORMAT_';

    private $ModuleDir;
    private $SemaphoreID;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->ModuleDir = __DIR__;
        $this->SemaphoreID = __CLASS__ . '_' . $InstanceID;
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('time_definitions', json_encode([]));

        $this->RegisterPropertyInteger('random', 0);

        $this->RegisterPropertyInteger('eventID', 0);

        $this->RegisterPropertyString('actions', json_encode([]));

        $this->RegisterPropertyString('events', json_encode([]));

        $this->RegisterPropertyString('date_format', '');

        $this->RegisterPropertyInteger('holiday_scriptID', 0);

        $this->RegisterPropertyString('update_time', '{"hour":0,"minute":0,"second":0}');
        $this->RegisterPropertyBoolean('update_promptly', true);

        $this->RegisterAttributeString('UpdateInfo', '');
        $this->RegisterAttributeString('state', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->RegisterTimer('CheckConditions', 0, $this->GetModulePrefix() . '_CheckConditions(' . $this->InstanceID . ', false);');
        $this->RegisterTimer('ExecuteAction', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "ExecuteAction", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        parent::MessageSink($timestamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            IPS_RunScriptText('IPS_RequestAction(' . $this->InstanceID . ',"CheckConditions", false);');
        }

        if (IPS_GetKernelRunlevel() == KR_READY && $message == VM_UPDATE && $data[1] == true /* changed */) {
            $this->SendDebug(__FUNCTION__, 'timestamp=' . $timestamp . ', senderID=' . $senderID . ', message=' . $message . ', data=' . print_r($data, true), 0);
            IPS_RunScriptText('IPS_RequestAction(' . $this->InstanceID . ',"CheckConditions", false);');
        }

        if (IPS_GetKernelRunlevel() == KR_READY && $message > IPS_EVENTMESSAGE && $message < IPS_EVENTMESSAGE + 100) {
            $this->SendDebug(__FUNCTION__, 'timestamp=' . $timestamp . ', senderID=' . $senderID . ', message=' . $message . ', data=' . print_r($data, true), 0);
            $this->MaintainTimer('CheckConditions', 1000);
        }
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        $eventID = $this->ReadPropertyInteger('eventID');
        if (IPS_EventExists($eventID) == false) {
            $this->SendDebug(__FUNCTION__, '"eventID" must be defined', 0);
            $r[] = $this->Translate('Weekplan event must be defined');
        } else {
            $event = IPS_GetEvent($eventID);
            if ($event['EventType'] != EVENTTYPE_SCHEDULE) {
                $this->SendDebug(__FUNCTION__, '"eventID" must must be a schedule event', 0);
                $r[] = $this->Translate('Weekplan event has the wrong event type');
            }
        }

        $time_definitions = json_decode($this->ReadPropertyString('time_definitions'), true);
        if ($time_definitions == false || count($time_definitions) == 0) {
            $this->SendDebug(__FUNCTION__, 'at least one range must be defined', 0);
            $r[] = $this->Translate('At least one range must be defined');
        } else {
            for ($actionID = 1; $actionID <= count($time_definitions); $actionID++) {
                $time_def = $time_definitions[$actionID - 1];
                $name = $time_def['name'];
                if ($name == '') {
                    $this->SendDebug(__FUNCTION__, 'name for range ' . $actionID . ' must be defined', 0);
                    $r[] = $this->TranslateFormat('Name for range {$actionID} must be defined', ['{$actionID}' => $actionID]);
                    continue;
                }
                $varID = $time_def['varID'];
                if (IPS_VariableExists($varID)) {
                    $var = IPS_GetVariable($varID);
                    $varprof = $var['VariableCustomProfile'];
                    if ($varprof == false) {
                        $varprof = $var['VariableProfile'];
                    }
                    if (preg_match('/^~UnixTimestamp/', $varprof) == false) {
                        $this->SendDebug(__FUNCTION__, '"varID" for range ' . $actionID . ' must have variable profile "~UnixTimestamp"', 0);
                        $r[] = $this->TranslateFormat('Reference variable for range {$actionID} must have variable profile "~UnixTimestamp"', ['{$actionID}' => $actionID]);
                    }
                }
                if (isset($time_def['events'])) {
                    $events = $time_def['events'];
                    foreach ($events as $event) {
                        $evnID = $event['eventID'];
                        if (IPS_EventExists($evnID)) {
                            $evn = IPS_GetEvent($evnID);
                            if ($evn['EventType'] != EVENTTYPE_CYCLIC) {
                                $this->SendDebug(__FUNCTION__, '"eventID" for range ' . $actionID . ' must be a cyclic event', 0);
                                $r[] = $this->TranslateFormat('Events for range {$actionID} must by cyclic events', ['{$actionID}' => $actionID]);
                            }
                        }
                    }
                }
            }
        }

        return $r;
    }

    private function CheckModuleUpdate(array $oldInfo, array $newInfo)
    {
        $this->SendDebug(__FUNCTION__, 'old=' . print_r($oldInfo, true) . ', new=' . print_r($newInfo, true), 0);
        $r = [];

        if ($this->version2num($oldInfo) < $this->version2num('1.2')) {
            $r[] = $this->Translate('Add time unit and recalculate time offset');
        }

        if ($this->version2num($oldInfo) < $this->version2num('1.3')) {
            $r[] = $this->Translate('Fix timeunit of offset');
            $r[] = $this->Translate('Create actions from possibly configured PHP code');
        }

        if ($this->version2num($oldInfo) < $this->version2num('1.4')) {
            $r[] = $this->Translate('Move actions to the switchtime range definitions');
        }

        return $r;
    }

    private function CompleteModuleUpdate(array $oldInfo, array $newInfo)
    {
        $this->SendDebug(__FUNCTION__, 'old=' . print_r($oldInfo, true) . ', new=' . print_r($newInfo, true), 0);
        if ($this->version2num($oldInfo) < $this->version2num('1.2')) {
            $time_definitions = json_decode($this->ReadPropertyString('time_definitions'), true);
            for ($i = 0; $i < count($time_definitions); $i++) {
                $offset = $time_definitions[$i]['offset'];
                $unit = self::$TIMEUNIT_SECONDS;
                if ($offset % 60 == 0) {
                    $offset /= 60;
                    $unit = self::$TIMEUNIT_MINUTES;
                    if ($offset % 60 == 0) {
                        $offset /= 60;
                        $unit = self::$TIMEUNIT_HOURS;
                    }
                }
                $time_definitions[$i]['offset'] = $offset;
                $time_definitions[$i]['offset_timeunit'] = $unit;
            }
            IPS_SetProperty($this->InstanceID, 'time_definitions', json_encode($time_definitions));
        }

        if ($this->version2num($oldInfo) < $this->version2num('1.3')) {
            $new_defs = [];
            $time_definitions = json_decode($this->ReadPropertyString('time_definitions'), true);
            foreach ($time_definitions as $time_def) {
                $def = [
                    'name'   => $time_def['name'],
                    'varID'  => $time_def['varID'],
                    'offset' => $time_def['offset'],
                ];
                if (isset($time_def['offset_timeunit'])) {
                    $def['offset_timeunit'] = $time_def['offset_timeunit'];
                } elseif (isset($time_def['unit'])) {
                    $def['offset_timeunit'] = $time_def['unit'];
                } else {
                    $def['offset_timeunit'] = self::$TIMEUNIT_SECONDS;
                }
                $new_defs[] = $def;
            }
            IPS_SetProperty($this->InstanceID, 'time_definitions', json_encode($new_defs));

            $action_script = $this->ReadPropertyString('action_script');
            $actions = [];
            if ($action_script != '') {
                $action = [
                    'actionID'   => '{346AA8C1-30E0-1663-78EF-93EFADFAC650}',
                    'parameters' => [
                        'SCRIPT'      => $action_script,
                        'ENVIRONMENT' => 'Default',
                        'PARENT'      => 0,
                        'TARGET'      => 0,
                    ],
                ];
                for ($i = 0; $i < count($time_definitions); $i++) {
                    $actions[] = [
                        'actionID' => $i + 1,
                        'action'   => json_encode($action),
                    ];
                }
            }
            IPS_SetProperty($this->InstanceID, 'actions', json_encode($actions));
        }

        if ($this->version2num($oldInfo) < $this->version2num('1.4')) {
            $time_definitions = json_decode($this->ReadPropertyString('time_definitions'), true);
            $actions = json_decode($this->ReadPropertyString('actions'), true);

            $new_defs = [];
            for ($actionID = 1; $actionID <= count($time_definitions); $actionID++) {
                $time_def = $time_definitions[$actionID - 1];
                $new_actions = [];
                foreach ($actions as $action) {
                    if ($action['actionID'] == $actionID) {
                        $new_actions[] = ['action' => $action['action']];
                    }
                }
                $time_def['actions'] = $new_actions;
                $new_defs[] = $time_def;
            }
            IPS_SetProperty($this->InstanceID, 'time_definitions', json_encode($new_defs));
        }

        return '';
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = [
            'eventID',
            'holiday_scriptID',
        ];
        $this->MaintainReferences($propertyNames);

        $time_definitions = json_decode($this->ReadPropertyString('time_definitions'), true);
        foreach ($time_definitions as $time_def) {
            $varID = $time_def['varID'];
            if (IPS_VariableExists($varID)) {
                $this->RegisterReference($varID);
            }
            if (isset($time_def['actions'])) {
                $actions = $time_def['actions'];
                foreach ($actions as $action) {
                    $this->MaintainReferences4Action($action['action']);
                }
            }
            if (isset($time_def['events'])) {
                $events = $time_def['events'];
                foreach ($events as $event) {
                    $evnID = $event['eventID'];
                    if (IPS_EventExists($evnID)) {
                        $this->RegisterReference($evnID);
                    }
                }
            }
        }

        $messageIds = [
            VM_UPDATE,
            EM_CHANGEACTIVE,
            EM_ADDSCHEDULEGROUP,
            EM_REMOVESCHEDULEGROUP,
            EM_CHANGESCHEDULEGROUP,
            EM_ADDSCHEDULEGROUPPOINT,
            EM_REMOVESCHEDULEGROUPPOINT,
            EM_CHANGESCHEDULEGROUPPOINT,
        ];

        $this->UnregisterMessages($messageIds);

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainTimer('CheckConditions', 0);
            $this->MaintainTimer('ExecuteAction', 0);
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainTimer('CheckConditions', 0);
            $this->MaintainTimer('ExecuteAction', 0);
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainTimer('CheckConditions', 0);
            $this->MaintainTimer('ExecuteAction', 0);
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $varList = [];

        $vpos = 1;

        for ($actionID = 1; $actionID <= count($time_definitions); $actionID++) {
            $time_def = $time_definitions[$actionID - 1];
            $ident = self::$ident_raw_pfx . $actionID;
            $name = $time_def['name'];
            $this->MaintainVariable($ident, $name, VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);
            $varList[] = $ident;
        }

        $date_format = $this->ReadPropertyString('date_format');
        if ($date_format != '') {
            $vpos = 21;
            for ($actionID = 1; $actionID <= count($time_definitions); $actionID++) {
                $time_def = $time_definitions[$actionID - 1];
                $ident = self::$ident_fmt_pfx . $actionID;
                $name = $time_def['name'] . $this->Translate(' (formatted)');
                $this->MaintainVariable($ident, $name, VARIABLETYPE_STRING, '', $vpos++, true);
                $varList[] = $ident;
            }
        }

        $objList = [];
        $this->FindVariables($this->InstanceID, $objList);
        foreach ($objList as $obj) {
            $ident = $obj['ObjectIdent'];
            if (!in_array($ident, $varList)) {
                $this->SendDebug(__FUNCTION__, 'unregister variable: ident=' . $ident, 0);
                $this->UnregisterVariable($ident);
            }
        }

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainTimer('CheckConditions', 0);
            $this->MaintainTimer('ExecuteAction', 0);
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $this->MaintainStatus(IS_ACTIVE);

        $update_promptly = $this->ReadPropertyBoolean('update_promptly');
        if ($update_promptly) {
            $objIDs = [];
            $propertyNames = [
                'eventID',
            ];
            foreach ($propertyNames as $propertyName) {
                $objIDs[] = $this->ReadPropertyInteger($propertyName);
            }
            foreach ($time_definitions as $time_def) {
                $objIDs[] = $time_def['varID'];
            }
            $this->RegisterObjectMessages($objIDs, $messageIds);
        }

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->CheckConditions(false);
        }
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Switchtime determination');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance'
        ];

        $time_definitions = json_decode($this->ReadPropertyString('time_definitions'), true);
        for ($i = 0; $i < count($time_definitions); $i++) {
            $time_definitions[$i]['id'] = $i + 1;
        }

        $formElements[] = [
            'name'     => 'time_definitions',
            'type'     => 'List',
            'rowCount' => 4,
            'add'      => true,
            'delete'   => true,
            'columns'  => [
                [
                    'name'    => 'id',
                    'add'     => '',
                    'width'   => '50px',
                    'save'    => false,
                    'caption' => 'ID',
                ],
                [
                    'name'    => 'name',
                    'add'     => '',
                    'edit'    => [
                        'type' => 'ValidationTextBox',
                    ],
                    'width'   => '300px',
                    'caption' => 'Name of the switchtime range',
                ],
                [
                    'name'    => 'varID',
                    'add'     => 0,
                    'edit'    => [
                        'type'               => 'SelectVariable',
                        'validVariableTypes' => [VARIABLETYPE_INTEGER],
                    ],
                    'width'   => 'auto',
                    'caption' => 'Reference variable',
                ],
                [
                    'name'    => 'offset',
                    'add'     => 0,
                    'edit'    => [
                        'type'   => 'NumberSpinner',
                    ],
                    'width'   => '100px',
                    'caption' => 'Time offset',
                ],
                [
                    'name'    => 'offset_timeunit',
                    'add'     => self::$TIMEUNIT_MINUTES,
                    'edit'    => [
                        'type'    => 'Select',
                        'options' => $this->GetTimeunitAsOptions(),
                    ],
                    'width'   => '100px',
                    'caption' => 'Unit',
                ],
                [
                    'name'     => 'events',
                    'add'      => [],
                    'edit'     => [
                        'type'     => 'List',
                        'rowCount' => 4,
                        'add'      => true,
                        'delete'   => true,
                        'columns'  => [
                            [
                                'name'     => 'eventID',
                                'add'      => 0,
                                'edit'     => [
                                    'type' => 'SelectEvent',
                                ],
                                'width'   => 'auto',
                                'caption' => 'Event',
                            ],
                        ],
                    ],
                    'width'   => '100px',
                    'caption' => 'Events',
                ],
                [
                    'name'     => 'actions',
                    'add'      => [],
                    'edit'     => [
                        'type'     => 'List',
                        'rowCount' => 4,
                        'add'      => true,
                        'delete'   => true,
                        'columns'  => [
                            [
                                'name'     => 'action',
                                'add'      => false,
                                'edit'     => [
                                    'type'    => 'SelectAction',
                                ],
                                'width'   => 'auto',
                                'caption' => 'Action',
                            ],
                        ],
                    ],
                    'width'   => '100px',
                    'caption' => 'Actions',
                ]
            ],
            'values'   => $time_definitions,
            'caption'  => 'Switchtime range definitions',
        ];

        $formElements[] = [
            'name'    => 'random',
            'type'    => 'NumberSpinner',
            'minimum' => 0,
            'suffix'  => ' Seconds',
            'caption' => 'Maximum additional random time offset',
        ];

        $formElements[] = [
            'name'    => 'eventID',
            'type'    => 'SelectEvent',
            'width'   => '500px',
            'caption' => 'Weekplan event',
        ];

        $formElements[] = [
            'name'    => 'holiday_scriptID',
            'type'    => 'SelectScript',
            'width'   => '500px',
            'caption' => 'Script for recognizing holidays (treat like Sundays)',
        ];

        $formElements[] = [
            'name'    => 'update_time',
            'type'    => 'SelectTime',
            'caption' => 'Time for the cyclic determination of the switchtimes',
        ];
        $formElements[] = [
            'name'    => 'update_promptly',
            'type'    => 'CheckBox',
            'caption' => 'Redetermine switchtimes immediately after changes',
        ];

        $formElements[] = [
            'type'     => 'ExpansionPanel',
            'expanded' => false,
            'items'    => [
                [
                    'type'    => 'Label',
                    'caption' => 'Format for the additional representation of the timestamp as string, see https://www.php.net/manual/de/datetime.format.php',
                ],
                [
                    'name'    => 'date_format',
                    'type'    => 'ValidationTextBox',
                    'caption' => 'Presentation format',
                ],
            ],
            'caption' => 'Additional variables',
        ];

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            $formActions[] = $this->GetCompleteUpdateFormAction();

            $formActions[] = $this->GetInformationFormAction();
            $formActions[] = $this->GetReferencesFormAction();

            return $formActions;
        }

        if (IPS_EventExists($this->ReadPropertyInteger('eventID')) == false) {
            $formActions[] = [
                'type'    => 'Button',
                'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "createEventID", "");',
                'caption' => 'Create weekplan event',
            ];
        }

        $formActions[] = [
            'type'    => 'RowLayout',
            'items'   => [
                [
                    'type'    => 'Button',
                    'onClick' => $this->GetModulePrefix() . '_CheckConditions(' . $this->InstanceID . ', false);',
                    'caption' => 'Check conditions',
                ],
                [
                    'type'    => 'Button',
                    'onClick' => $this->GetModulePrefix() . '_CheckConditions(' . $this->InstanceID . ', true);',
                    'caption' => 'Check conditions, ignore today\'s run',
                ],
            ],
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();
        $formActions[] = $this->GetModuleActivityFormAction();

        return $formActions;
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
            case 'createEventID':
                $eventID = $this->CreateWeekplanEvent();
                $this->UpdateFormField('eventID', 'value', $eventID);
                break;
            case 'CheckConditions':
                $this->CheckConditions($value);
                break;
            case 'ExecuteAction':
                $this->ExecuteAction();
                break;
            default:
                $r = false;
                break;
        }
        return $r;
    }

    public function RequestAction($ident, $value)
    {
        if ($this->LocalRequestAction($ident, $value)) {
            return;
        }
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }

        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', value=' . $value, 0);

        $r = false;
        switch ($ident) {
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
        if ($r) {
            $this->SetValue($ident, $value);
        }
    }

    private function IsHoliday($tstamp)
    {
        $isHoliday = false;
        $holiday_scriptID = $this->ReadPropertyInteger('holiday_scriptID');
        if (IPS_ScriptExists($holiday_scriptID)) {
            $params = [
                'TSTAMP' => $tstamp,
            ];
            @$s = IPS_RunScriptWaitEx($holiday_scriptID, $params);
            $this->SendDebug(__FUNCTION__, '... IPS_RunScriptWaitEx(' . $holiday_scriptID . ', ' . print_r($params, true) . ')=' . $s, 0);
            $isHoliday = filter_var($s, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (is_null($isHoliday)) {
                $isHoliday = $s != '';
            }
        }
        return $isHoliday;
    }

    private function GetBoundaries($actionID, $event, $tstamp, $wday)
    {
        $start = '';
        $end = '';
        foreach ($event['ScheduleGroups'] as $group) {
            if ($group['Days'] & (2 ** $wday)) {
                foreach ($group['Points'] as $point) {
                    if ($start == false) {
                        if ($point['ActionID'] == $actionID) {
                            $start = sprintf('%02d:%02d:%02d', $point['Start']['Hour'], $point['Start']['Minute'], $point['Start']['Second']);
                        }
                    } else {
                        if ($point['ActionID'] != $actionID) {
                            $end = sprintf('%02d:%02d:%02d', $point['Start']['Hour'], $point['Start']['Minute'], $point['Start']['Second']);
                            break;
                        }
                    }
                }
                break;
            }
        }

        $r = [
            'start' => $start,
            'end'   => $end,
        ];
        return $r;
    }

    private function UpdateEvent($evnID, $tstamp)
    {
        $this->SendDebug(__FUNCTION__, 'eventID=' . $evnID . ', tstamp=' . $tstamp, 0);

        $ret = IPS_EventExists($evnID);

        if ($ret) {
            $ret = IPS_SetEventCyclic($evnID, 1 /* einmalig */, 0, 0, 1 /* einmalig */, 0, 0);
        }

        if ($tstamp) {
            $tm = getdate($tstamp);
            if ($ret) {
                $ret = IPS_SetEventCyclicDateFrom($evnID, $tm['mday'], $tm['mon'], $tm['year']);
            }
            if ($ret) {
                $ret = IPS_SetEventCyclicDateTo($evnID, 0, 0, 0);
            }
            if ($ret) {
                $ret = IPS_SetEventCyclicTimeFrom($evnID, $tm['hours'], $tm['minutes'], $tm['seconds']);
            }
            if ($ret) {
                $ret = IPS_SetEventCyclicTimeTo($evnID, 0, 0, 0);
            }
            if ($ret) {
                $ret = IPS_SetEventActive($evnID, true);
            }
        } else {
            if ($ret) {
                $ret = IPS_SetEventActive($evnID, false);
            }
        }
        return $ret;
    }

    public function CheckConditions(bool $force)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'force=' . $this->bool2str($force), 0);

        $this->SendDebug(__FUNCTION__, $this->PrintTimer('CheckConditions') . ', ' . $this->PrintTimer('ExecuteAction'), 0);

        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return;
        }

        $now_tstamp = time();
        $this->SendDebug(__FUNCTION__, 'now_tstamp=' . $this->date2str($now_tstamp), 0);
        $now_date = $this->GetTstampOfMidnight($now_tstamp);
        $now_sec = $this->GetSecFromMidnight(date('H:i:s', $now_tstamp));

        $random = $this->ReadPropertyInteger('random');
        $update_promptly = $this->ReadPropertyBoolean('update_promptly');
        $eventID = $this->ReadPropertyInteger('eventID');
        $event = IPS_GetEvent($eventID);

        $jstate = json_decode($this->ReadAttributeString('state'), true);
        $this->SendDebug(__FUNCTION__, 'state=' . print_r($jstate, true), 0);

        $check_tstamp = 0;
        $action_tstamp = 0;

        $date_format = $this->ReadPropertyString('date_format');

        $time_definitions = json_decode($this->ReadPropertyString('time_definitions'), true);
        for ($actionID = 1; $actionID <= count($time_definitions); $actionID++) {
            $time_def = $time_definitions[$actionID - 1];
            $this->SendDebug(__FUNCTION__, 'time_def=' . print_r($time_def, true), 0);

            $cond = [];
            $new_tstamp = 0;
            $delayed = false;

            $ident = self::$ident_raw_pfx . $actionID;
            $old_tstamp = $this->GetValue($ident);
            $old_sec = $this->GetSecFromMidnight(date('H:i:s', $old_tstamp));
            $old_date = $this->GetTstampOfMidnight($old_tstamp);
            $this->SendDebug(__FUNCTION__, 'name=' . $time_def['name'] . ', actionID=' . $actionID . ', old_tstamp=' . $this->date2str($old_tstamp), 0);

            $exec_tstamp = isset($jstate['executed'][$actionID]) ? $jstate['executed'][$actionID] : 0;
            $exec_sec = $this->GetSecFromMidnight(date('H:i:s', $exec_tstamp));
            $exec_date = $this->GetTstampOfMidnight($exec_tstamp);
            $this->SendDebug(__FUNCTION__, '... exec_tstamp=' . $this->date2str($exec_tstamp), 0);

            if ($event['EventActive']) {
                $varID = $time_def['varID'];
                if (IPS_VariableExists($varID)) {
                    $ref_tstamp = GetValueInteger($varID);
                    $this->SendDebug(__FUNCTION__, '... varID=' . $varID . ', ref_tstamp=' . $this->date2str($ref_tstamp), 0);

                    $new_tstamp = $ref_tstamp;
                    $cond[] = 'ref=' . $this->date2str($ref_tstamp);

                    $offset = (int) $time_def['offset'];
                    if ($offset) {
                        if (isset($time_def['offset_timeunit'])) {
                            $offset = $this->CalcByTimeunit($time_def['offset_timeunit'], $offset);
                            $cond[] = 'offset=' . $time_def['offset'] . $this->Timeunit2Suffix($time_def['offset_timeunit']);
                        } else {
                            $cond[] = 'offset=' . $offset . 's';
                        }
                        $new_tstamp += $offset;
                    }
                } else {
                    $ref_tstamp = 0;
                    $cond[] = 'no ref';

                    $new_tstamp = $now_date;
                    $wday = (int) date('N', $new_tstamp) - 1;
                    if ($wday != 6 && $this->IsHoliday($new_tstamp)) {
                        $wday = 6;
                    }
                    $boundaries = $this->GetBoundaries($actionID, $event, $new_tstamp, $wday);
                    $new_tstamp += $this->GetSecFromMidnight($boundaries['start']);
                    if ($new_tstamp <= $now_tstamp) {
                        $new_tstamp = $now_date + 86400;
                        $wday = (int) date('N', $new_tstamp) - 1;
                        if ($wday != 6 && $this->IsHoliday($new_tstamp)) {
                            $wday = 6;
                        }
                        $boundaries = $this->GetBoundaries($actionID, $event, $new_tstamp, $wday);
                        $new_tstamp += $this->GetSecFromMidnight($boundaries['start']);
                    }
                }

                if ($random > 0) {
                    $rand_offset = rand(0, $random);
                    $new_tstamp += $rand_offset;
                    $cond[] = 'random=' . $rand_offset . 's';
                }

                $new_date = $this->GetTstampOfMidnight($new_tstamp);
                $new_sec = $this->GetSecFromMidnight(date('H:i:s', $new_tstamp));
                $wday = (int) date('N', $new_tstamp) - 1;
                $cond[] = 'wday=' . $wday;

                $this->SendDebug(__FUNCTION__, '... new_tstamp=' . $this->date2str($new_tstamp) . ', wday=' . $wday, 0);

                if ($wday != 6 && $this->IsHoliday($new_tstamp)) {
                    $wday = 6; // Sonntag
                    $this->SendDebug(__FUNCTION__, '... is holiday, wday => ' . $wday, 0);
                    $cond[] = 'holiday (wday=6)';
                }

                $boundaries = $this->GetBoundaries($actionID, $event, $new_tstamp, $wday);
                $start = $boundaries['start'];
                $end = $boundaries['end'];
                $this->SendDebug(__FUNCTION__, '... found range: start=' . $start . ', end=' . $end, 0);
                if ($start != '') {
                    $start_sec = $this->GetSecFromMidnight($start);
                    if ($new_sec < $start_sec) {
                        $new_tstamp = $new_tstamp - $new_sec + $start_sec;
                        $this->SendDebug(__FUNCTION__, '... before start boundary, adjusted new_tstamp=' . $this->date2str($new_tstamp), 0);
                        $new_sec = $this->GetSecFromMidnight(date('H:i:s', $new_tstamp));
                        $cond[] = 'before ' . $start;
                    }
                }
                if ($end != '') {
                    $end_sec = $this->GetSecFromMidnight($end);
                    if ($new_sec > $end_sec) {
                        $new_tstamp = $new_tstamp - $new_sec + $end_sec;
                        $this->SendDebug(__FUNCTION__, '... after end boundary, adjusted new_tstamp=' . $this->date2str($new_tstamp), 0);
                        $new_sec = $this->GetSecFromMidnight(date('H:i:s', $new_tstamp));
                        $cond[] = 'after ' . $end;
                    }
                }

                if ($new_tstamp == $old_tstamp) {
                    $this->SendDebug(__FUNCTION__, '... ident=' . $ident . ' remains unchanged at ' . $this->date2str($new_tstamp), 0);
                    if ($date_format != '') {
                        $ident = self::$ident_fmt_pfx . $actionID;
                        $s = $new_tstamp ? date($date_format, $new_tstamp) : '';
                        if ($s != $this->GetValue($ident)) {
                            $this->SetValue($ident, $s);
                        }
                    }

                    if (isset($time_def['events'])) {
                        $_msg = [];

                        $events = $time_def['events'];
                        foreach ($events as $e) {
                            $r = $this->UpdateEvent($e['eventID'], $new_tstamp);
                            $_msg[] = (string) $e['eventID'] . ($r ? '=ok' : '=fail');
                        }

                        if ($_msg != []) {
                            $msg = 'time-definition ' . $actionID . ': update event ' . implode(', ', $_msg);
                            $this->AddModuleActivity($msg);
                        }
                    }

                    if ($new_tstamp > $now_tstamp) {
                        if ($action_tstamp == 0 || $new_tstamp < $action_tstamp) {
                            $action_tstamp = $new_tstamp;
                        }
                        $this->SendDebug(__FUNCTION__, '... execute in ' . ($new_tstamp - $now_tstamp) . 's', 0);
                        continue;
                    }
                }

                $_action_tstamp = 0;
                if ($new_tstamp > $now_tstamp) {
                    $_action_tstamp = $new_tstamp;
                }

                $this->SendDebug(__FUNCTION__, '... update_promptly=' . $this->bool2str($update_promptly) . ', force=' . $this->bool2str($force), 0);
                if ($update_promptly && $force == false) {
                    $was_today = $force == false && $exec_date == $now_date && $exec_sec <= $now_sec;
                    $this->SendDebug(__FUNCTION__, '... was_today=' . $this->bool2str($was_today), 0);

                    $_check_tstamp = 0;
                    // kommt noch heute, daher keine Änderung des TS auf > heute
                    // -> aktion ausführen und danach neu berechnen
                    if ($was_today == false && $old_date == $now_date && $new_date > $now_date && $old_tstamp > $now_tstamp) {
                        $_check_tstamp = $now_date + $old_sec + 1;
                        $_action_tstamp = $old_tstamp;
                    }
                    // war heute und würde heute erneut kommen und die Uhrzeit wäre später
                    // -> erst auswerten, wenn die Uhrzeit verstrichen ist
                    if ($was_today && $new_sec > $now_sec) {
                        $_check_tstamp = $now_date + $new_sec;
                    }
                    if ($_check_tstamp) {
                        if ($check_tstamp == 0 || $_check_tstamp < $check_tstamp) {
                            $check_tstamp = $_check_tstamp;
                        }
                        $delayed = true;
                        $this->SendDebug(__FUNCTION__, '... change ' . ($_check_tstamp - $now_tstamp) . 's delayed', 0);
                        $msg = 'time-definition ' . $actionID . ': delayed (only run once a day)';
                        $this->AddModuleActivity($msg);
                    }
                }

                if ($_action_tstamp) {
                    if ($action_tstamp == 0 || $_action_tstamp < $action_tstamp) {
                        $action_tstamp = $_action_tstamp;
                    }
                    $this->SendDebug(__FUNCTION__, '... execute in ' . ($_action_tstamp - $now_tstamp) . 's', 0);
                }
            } else {
                $this->SendDebug(__FUNCTION__, '... event is inactive', 0);
            }

            if ($delayed == false) {
                if ($new_tstamp == time()) {
                    $new_tstamp += 1; // damit der neuen Wert auf jeden Fall in der Zukunft liegt
                }
                $this->SendDebug(__FUNCTION__, '... ident=' . $ident . ' changes to ' . $this->date2str($new_tstamp), 0);
                $this->SetValue($ident, $new_tstamp);

                if ($date_format != '') {
                    $ident = self::$ident_fmt_pfx . $actionID;
                    $s = $new_tstamp ? date($date_format, $new_tstamp) : '';
                    $this->SetValue($ident, $s);
                }

                if (isset($time_def['events'])) {
                    $_msg = [];

                    $events = $time_def['events'];
                    foreach ($events as $e) {
                        $r = $this->UpdateEvent($e['eventID'], $new_tstamp);
                        $_msg[] = (string) $e['eventID'] . ($r ? '=ok' : '=fail');
                    }

                    if ($_msg != []) {
                        $msg = 'time-definition ' . $actionID . ': update event ' . implode(', ', $_msg);
                        $this->AddModuleActivity($msg);
                    }
                }

                $msg = 'time-definition ' . $actionID . ': tstamp new=' . $this->date2str($new_tstamp);
                if ($cond != []) {
                    $msg .= ' (' . implode(', ', $cond) . ')';
                }
                $this->AddModuleActivity($msg);
            }
        }

        $update_time = json_decode($this->ReadPropertyString('update_time'), true);
        $next_tstamp = $now_date + $update_time['hour'] * 3600 + $update_time['minute'] * 60 + $update_time['second'];
        // nächster check immer in der zukunft
        if ($next_tstamp <= time()) {
            $next_tstamp += 86400;
        }
        $this->SendDebug(__FUNCTION__, 'next regular check=' . $this->date2str($next_tstamp), 0);
        if ($check_tstamp == 0 || $next_tstamp < $check_tstamp) {
            $check_tstamp = $next_tstamp;
        }

        $sleep4action = $action_tstamp ? $action_tstamp - time() : 0;
        $sleep4check = $check_tstamp ? $check_tstamp - time() : 0;

        // bei gleichzeitigem check und action immer zuerst action
        if ($sleep4check && $sleep4check == $sleep4action) {
            $sleep4check++;
        }

        $this->SendDebug(__FUNCTION__, 'sleep4check=' . $sleep4check . 's, sleep4action=' . $sleep4action . 's', 0);
        $this->MaintainTimer('CheckConditions', $sleep4check * 1000);
        $this->MaintainTimer('ExecuteAction', $sleep4action * 1000);

        IPS_SemaphoreLeave($this->SemaphoreID);

        $timer = $this->GetTimerByName('CheckConditions');
        $msg = 'next check=' . $this->date2str($timer['NextRun']);

        $timer = $this->GetTimerByName('ExecuteAction');
        $msg .= ', next execution=' . $this->date2str($timer['NextRun']);

        $this->AddModuleActivity($msg);
    }

    private function ExecuteAction()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, $this->PrintTimer('CheckConditions') . ', ' . $this->PrintTimer('ExecuteAction'), 0);

        $now_tstamp = time();

        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return;
        }

        $action_tstamp = 0;

        $jstate = json_decode($this->ReadAttributeString('state'), true);
        $this->SendDebug(__FUNCTION__, 'state=' . print_r($jstate, true), 0);

        $time_definitions = json_decode($this->ReadPropertyString('time_definitions'), true);
        for ($actionID = 1; $actionID <= count($time_definitions); $actionID++) {
            $time_def = $time_definitions[$actionID - 1];

            $ident = self::$ident_raw_pfx . $actionID;
            $cur_tstamp = $this->GetValue($ident);

            $this->SendDebug(__FUNCTION__, 'name=' . $time_def['name'] . ', actionID=' . $actionID . ', cur_tstamp=' . $this->date2str($cur_tstamp), 0);

            $exec_tstamp = isset($jstate['executed'][$actionID]) ? $jstate['executed'][$actionID] : 0;
            $this->SendDebug(__FUNCTION__, '... exec_tstamp=' . $this->date2str($exec_tstamp), 0);

            // es gibt manchmal gewisse Verzögerungen im Timer-Aufruf
            $diff = $now_tstamp - $cur_tstamp;
            if ($diff >= 0 && $diff < 5) {
                if (isset($time_def['actions'])) {
                    $_msg = [];

                    $actions = $time_def['actions'];
                    for ($i = 0; $i < count($actions); $i++) {
                        $a = $actions[$i];
                        $action = json_decode($a['action'], true);
                        $params = $action['parameters'];
                        $params['actionID'] = $actionID;
                        @$r = IPS_RunAction($action['actionID'], $params);
                        $this->SendDebug(__FUNCTION__, '... IPS_RunAction(' . $action['actionID'] . ', ' . print_r($params, true) . ') => ' . $r, 0);
                        $_msg[] = (string) $i . ($r ? '=ok' : '=fail');
                    }

                    if ($_msg != []) {
                        $msg = 'time-definition ' . $actionID . ': run entry ' . implode(', ', $_msg);
                        $this->AddModuleActivity($msg);
                    }
                }
                $jstate['executed'][$actionID] = $now_tstamp;
                $this->SendDebug(__FUNCTION__, '... new exec_tstamp=' . $this->date2str($now_tstamp), 0);
            }
            if ($cur_tstamp > $now_tstamp) {
                if ($action_tstamp == 0 || $cur_tstamp < $action_tstamp) {
                    $action_tstamp = $cur_tstamp;
                }
                $this->SendDebug(__FUNCTION__, '... execute in ' . ($cur_tstamp - $now_tstamp) . 's', 0);
            }
        }

        $this->SendDebug(__FUNCTION__, 'new state=' . print_r($jstate, true), 0);
        $this->WriteAttributeString('state', json_encode($jstate));

        $sleep4action = $action_tstamp ? $action_tstamp - time() : 0;
        $this->SendDebug(__FUNCTION__, 'sleep4action=' . $sleep4action . 's', 0);
        $this->MaintainTimer('ExecuteAction', $sleep4action * 1000);

        IPS_SemaphoreLeave($this->SemaphoreID);

        $timer = $this->GetTimerByName('ExecuteAction');
        $msg = 'next execution=' . $this->date2str($timer['NextRun']);

        $this->AddModuleActivity($msg);
    }

    private function GetSecFromMidnight($str)
    {
        $r = explode(':', $str);
        $h = isset($r[0]) ? intval($r[0]) : 0;
        $m = isset($r[1]) ? intval($r[1]) : 0;
        $s = isset($r[2]) ? intval($r[2]) : 0;
        return $h * 3600 + $m * 60 + $s;
    }

    private function GetTstampOfMidnight($tstamp)
    {
        $dt = new DateTime(date('d.m.Y 00:00:00', $tstamp));
        return (int) $dt->format('U');
    }

    private function date2str($tstamp)
    {
        return $tstamp ? date('d.m.Y H:i:s', $tstamp) : '-';
    }

    private function CreateWeekplanEvent()
    {
        $colors = [
            0xD6D6D6,
            0xFEFB41,
            0x0056D6,
            0xFFA500,
        ];

        $eventID = IPS_CreateEvent(EVENTTYPE_SCHEDULE);
        IPS_SetEventScheduleAction($eventID, 0, $this->Translate('Rest period'), $colors[0], '');
        $time_definitions = json_decode($this->ReadPropertyString('time_definitions'), true);
        for ($actionID = 1; $actionID <= count($time_definitions); $actionID++) {
            $time_def = $time_definitions[$actionID - 1];
            $name = $time_def['name'];
            $color = isset($colors[$actionID]) ? $colors[$actionID] : 0;
            IPS_SetEventScheduleAction($eventID, $actionID, $name, $color, '');
        }
        IPS_SetEventScheduleGroup($eventID, 0, 0b1111111);
        IPS_SetEventScheduleGroupPoint($eventID, 0, 0, 0, 0, 0, 0);
        IPS_SetName($eventID, $this->Translate('Switchtime ranges'));
        IPS_SetParent($eventID, $this->InstanceID);
        IPS_SetEventActive($eventID, true);

        return $eventID;
    }

    private function FindVariables($objID, &$objList)
    {
        $chldIDs = IPS_GetChildrenIDs($objID);
        foreach ($chldIDs as $chldID) {
            $obj = IPS_GetObject($chldID);
            switch ($obj['ObjectType']) {
                case OBJECTTYPE_VARIABLE:
                    if (preg_match('#^' . self::$ident_raw_pfx . '#', $obj['ObjectIdent'], $r)) {
                        $objList[] = $obj;
                    }
                    if (preg_match('#^' . self::$ident_fmt_pfx . '#', $obj['ObjectIdent'], $r)) {
                        $objList[] = $obj;
                    }
                    break;
                case OBJECTTYPE_CATEGORY:
                    $this->FindVariables($chldID, $objList);
                    break;
                default:
                    break;
            }
        }
    }
}
