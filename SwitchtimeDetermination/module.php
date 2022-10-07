<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class SwitchtimeDetermination extends IPSModule
{
    use SwitchtimeDetermination\StubsCommonLib;
    use SwitchtimeDeterminationLocalLib;

    private $ModuleDir;

    public static $ident_raw_pfx = 'SWITCHTIME_';
    public static $ident_fmt_pfx = 'FORMAT_';

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->ModuleDir = __DIR__;
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('time_definitions', json_encode([]));

        $this->RegisterPropertyInteger('random', 0);

        $this->RegisterPropertyInteger('eventID', 0);

        $this->RegisterPropertyString('action_script', '');

        $this->RegisterPropertyString('date_format', '');

        $this->RegisterPropertyInteger('holiday_scriptID', 0);

        $this->RegisterPropertyString('update_time', '{"hour":0,"minute":0,"second":0}');
        $this->RegisterPropertyBoolean('update_promptly', true);

        $this->RegisterAttributeString('UpdateInfo', '');

        $this->InstallVarProfiles(false);

        $this->RegisterTimer('CheckConditions', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "CheckConditions", "");');
        $this->RegisterTimer('ExecuteAction', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "ExecuteAction", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        parent::MessageSink($timestamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            $this->CheckConditions();
        }

        if (IPS_GetKernelRunlevel() == KR_READY && in_array($message, [VM_UPDATE, EM_UPDATE])) {
            $this->SendDebug(__FUNCTION__, 'timestamp=' . $timestamp . ', senderID=' . $senderID . ', message=' . $message . ', data=' . print_r($data, true), 0);
            $this->MaintainTimer('CheckConditions', 500);
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
                        $r[] = $this->Translate('Reference variable for "{$name}" must have variable profile "~UnixTimestamp"', ['{$actionID}' => $actionID, '{$name}' => $name]);
                    }
                }
            }
        }

        return $r;
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = [
            'eventID',
            'holiday_scriptID',
        ];
        $this->MaintainReferences($propertyNames);

        $propertyNames = ['action_script'];
        foreach ($propertyNames as $name) {
            $text = $this->ReadPropertyString($name);
            $this->MaintainReferences4Script($text);
        }

        $time_definitions = json_decode($this->ReadPropertyString('time_definitions'), true);
        foreach ($time_definitions as $time_def) {
            $varID = $time_def['varID'];
            if (IPS_VariableExists($varID)) {
                $this->RegisterReference($varID);
            }
        }

        $messageIds = [
            VM_UPDATE,
            EM_UPDATE,
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
        $this->findVariables($this->InstanceID, $objList);
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
            $this->CheckConditions();
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

        $formElements[] = [
            'name'     => 'time_definitions',
            'type'     => 'List',
            'rowCount' => 3,
            'add'      => true,
            'delete'   => true,
            'columns'  => [
                [
                    'name'    => 'name',
                    'add'     => '',
                    'edit'    => [
                        'type' => 'ValidationTextBox',
                    ],
                    'width'   => 'auto',
                    'caption' => 'Name of the switchtime range',
                ],
                [
                    'name'    => 'varID',
                    'add'     => 0,
                    'edit'    => [
                        'type'               => 'SelectVariable',
                        'validVariableTypes' => [VARIABLETYPE_INTEGER],
                    ],
                    'width'   => '500px',
                    'caption' => 'Reference variable',
                ],
                [
                    'name'    => 'offset',
                    'add'     => 0,
                    'edit'    => [
                        'type'   => 'NumberSpinner',
                        'suffix' => ' Seconds',
                    ],
                    'width'   => '250px',
                    'caption' => 'Time offset',
                ],
            ],
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

        $formElements[] = [
            'type'     => 'ExpansionPanel',
            'expanded' => false,
            'items'    => [
                [
                    'name'     => 'action_script',
                    'type'     => 'ScriptEditor',
                    'rowCount' => 10,
                ],
            ],
            'caption'  => 'PHP code to be executed at switchtime',
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
            'type'    => 'Button',
            'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "CheckConditions", "");',
            'caption' => 'Check conditions',
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

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
                $this->CheckConditions();
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

    private function CheckConditions()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $random = $this->ReadPropertyInteger('random');
        $update_promptly = $this->ReadPropertyBoolean('update_promptly');
        $holiday_scriptID = $this->ReadPropertyInteger('holiday_scriptID');
        $eventID = $this->ReadPropertyInteger('eventID');
        $event = IPS_GetEvent($eventID);

        $now_tstamp = time();
        $this->SendDebug(__FUNCTION__, 'now_tstamp=' . date('d.m.Y H:i:s', $now_tstamp), 0);
        $now_date = $this->GetTstampOfMidnight($now_tstamp);
        $now_sec = $this->GetSecFromMidnight(date('H:i:s', $now_tstamp));

        $sleep4check = 0;
        $sleep4action = 0;

        $action_script = $this->ReadPropertyString('action_script');
        $date_format = $this->ReadPropertyString('date_format');

        $time_definitions = json_decode($this->ReadPropertyString('time_definitions'), true);
        for ($actionID = 1; $actionID <= count($time_definitions); $actionID++) {
            $time_def = $time_definitions[$actionID - 1];

            $delayed = false;

            $ident = self::$ident_raw_pfx . $actionID;
            $new_tstamp = 0;
            if ($event['EventActive']) {
                $old_tstamp = $this->GetValue($ident);
                $old_sec = $this->GetSecFromMidnight(date('H:i:s', $old_tstamp));

                $this->SendDebug(__FUNCTION__, 'name=' . $time_def['name'] . ', actionID=' . $actionID . ', old_tstamp=' . date('d.m.Y H:i:s', $old_tstamp), 0);

                $varID = $time_def['varID'];
                if (IPS_VariableExists($varID)) {
                    $var_tstamp = GetValueInteger($varID);
                    $this->SendDebug(__FUNCTION__, '... varID=' . $varID . ', var_tstamp=' . date('d.m.Y H:i:s', $var_tstamp), 0);

                    $new_tstamp = $var_tstamp + (int) $time_def['offset'];
                    if ($random > 0) {
                        $new_tstamp += rand(0, $random);
                    }
                } else {
                    $new_tstamp = $now_date;
                    if ($old_tstamp < $now_tstamp) {
                        $new_tstamp += 86400;
                    }
                    if ($random > 0) {
                        $new_tstamp += rand(0, $random);
                    }
                }

                $new_sec = $this->GetSecFromMidnight(date('H:i:s', $new_tstamp));
                $wday = (int) date('N', $new_tstamp) - 1;

                $this->SendDebug(__FUNCTION__, '... new_tstamp=' . date('d.m.Y H:i:s', $new_tstamp) . ', wday=' . $wday, 0);

                if (IPS_ScriptExists($holiday_scriptID)) {
                    $params = [
                        'TSTAMP' => $new_tstamp,
                    ];
                    @$s = IPS_RunScriptWaitEx($holiday_scriptID, $params);
                    $this->SendDebug(__FUNCTION__, '... IPS_RunScriptWaitEx(' . $holiday_scriptID . ', ' . print_r($params, true) . ')=' . $s, 0);
                    $isHoliday = filter_var($s, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if (is_null($isHoliday)) {
                        $isHoliday = $s != '';
                    }
                    if ($isHoliday) {
                        $wday = 6; // Sonntag
                        $this->SendDebug(__FUNCTION__, '... is holiday, wday => ' . $wday, 0);
                    }
                }

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
                $this->SendDebug(__FUNCTION__, '... found range: start=' . $start . ', end=' . $end, 0);

                if ($start != '') {
                    $start_sec = $this->GetSecFromMidnight($start);
                    if ($new_sec < $start_sec) {
                        $new_tstamp = $new_tstamp - $new_sec + $start_sec;
                        $this->SendDebug(__FUNCTION__, '... below border, abdjusted new_tstamp=' . date('d.m.Y H:i:s', $new_tstamp), 0);
                        $new_sec = $this->GetSecFromMidnight(date('H:i:s', $new_tstamp));
                    }
                }
                if ($end != '') {
                    $end_sec = $this->GetSecFromMidnight($end);
                    if ($new_sec > $end_sec) {
                        $new_tstamp = $new_tstamp - $new_sec + $end_sec;
                        $this->SendDebug(__FUNCTION__, '... above border, abdjusted new_tstamp=' . date('d.m.Y H:i:s', $new_tstamp), 0);
                        $new_sec = $this->GetSecFromMidnight(date('H:i:s', $new_tstamp));
                    }
                }

                if ($action_script != '' && $new_tstamp > $now_tstamp) {
                    $dif = $new_tstamp - $now_tstamp;
                    if ($sleep4action == 0 || $sleep4action > $dif) {
                        $sleep4action = $dif;
                    }
                    $this->SendDebug(__FUNCTION__, '... execute in ' . $dif . 's', 0);
                }

                if ($new_tstamp == $old_tstamp) {
                    $this->SendDebug(__FUNCTION__, '... ident=' . $ident . ' remains unchanged at ' . date('d.m.Y H:i:s', $new_tstamp), 0);
                    if ($date_format != '') {
                        $ident = self::$ident_fmt_pfx . $actionID;
                        $s = $new_tstamp ? date($date_format, $new_tstamp) : '';
                        if ($s != $this->GetValue($ident)) {
                            $this->SetValue($ident, $s);
                        }
                    }
                    continue;
                }

                if ($update_promptly) {
                    $old_date = $this->GetTstampOfMidnight($old_tstamp);
                    $new_date = $this->GetTstampOfMidnight($new_tstamp);
                    $was_today = $old_date == $now_date && $old_sec <= $now_sec;
                    $will_today = $new_date == $now_date && $new_sec > $now_sec;
                    if ($new_sec > $now_sec && $was_today && $will_today) {
                        $dif = $now_date + $new_sec - $now_tstamp;
                        if ($sleep4check == 0 || $sleep4check > $dif) {
                            $sleep4check = $dif;
                        }
                        $delayed = true;
                        $this->SendDebug(__FUNCTION__, '... change ' . $dif . 's delayed', 0);
                    }
                }
            }
            if ($delayed == false) {
                if ($new_tstamp == time()) {
                    $new_tstamp += 1; // damit der neuen Wert auf jeden Fall in der Zukunft liegt
                }
                $this->SendDebug(__FUNCTION__, '... ident=' . $ident . ' changes to ' . date('d.m.Y H:i:s', $new_tstamp), 0);
                $this->SetValue($ident, $new_tstamp);

                if ($date_format != '') {
                    $ident = self::$ident_fmt_pfx . $actionID;
                    $s = $new_tstamp ? date($date_format, $new_tstamp) : '';
                    $this->SetValue($ident, $s);
                }
            }
        }

        $update_time = json_decode($this->ReadPropertyString('update_time'), true);
        $next_tstamp = $now_date + $update_time['hour'] * 3600 + $update_time['minute'] * 60 + $update_time['second'];
        if ($next_tstamp < $now_tstamp) {
            $next_tstamp += 86400;
        }
        $dif = $next_tstamp - $now_tstamp;
        if ($sleep4check == 0 || $sleep4check > $dif) {
            $sleep4check = $dif;
        }
        $this->MaintainTimer('CheckConditions', $sleep4check * 1000);

        $this->MaintainTimer('ExecuteAction', $sleep4action * 1000);
    }

    private function ExecuteAction()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $now_tstamp = time();

        $sleep4action = 0;

        $action_script = $this->ReadPropertyString('action_script');
        if ($action_script != '') {
            $time_definitions = json_decode($this->ReadPropertyString('time_definitions'), true);
            for ($actionID = 1; $actionID <= count($time_definitions); $actionID++) {
                $time_def = $time_definitions[$actionID - 1];

                $ident = self::$ident_raw_pfx . $actionID;
                $cur_tstamp = $this->GetValue($ident);

                $this->SendDebug(__FUNCTION__, 'name=' . $time_def['name'] . ', actionID=' . $actionID . ', old_tstamp=' . date('d.m.Y H:i:s', $cur_tstamp), 0);

                if ($cur_tstamp == $now_tstamp) {
                    $params = [
                        'actionID'   => $actionID,
                        'instanceID' => $this->InstanceID,
                    ];
                    @$r = IPS_RunScriptTextWaitEx($action_script, $params);
                    $this->SendDebug(__FUNCTION__, '... execute script("...", ' . print_r($params, true) . ' => ' . $r, 0);
                }

                if ($cur_tstamp > $now_tstamp) {
                    $dif = $cur_tstamp - $now_tstamp;
                    if ($sleep4action == 0 || $sleep4action > $dif) {
                        $sleep4action = $dif;
                    }
                    $this->SendDebug(__FUNCTION__, '... execute in ' . $dif . 's', 0);
                }
            }
        }

        $this->MaintainTimer('ExecuteAction', $sleep4action * 1000);
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

    private function findVariables($objID, &$objList)
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
                    $this->findVariables($chldID, $objList);
                    break;
                default:
                    break;
            }
        }
    }
}
