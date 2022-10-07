<?php

declare(strict_types=1);

trait SwitchtimeDeterminationLocalLib
{
    private function GetFormStatus()
    {
        $formStatus = $this->GetCommonFormStatus();

        return $formStatus;
    }

    public static $STATUS_INVALID = 0;
    public static $STATUS_VALID = 1;
    public static $STATUS_RETRYABLE = 2;

    private function CheckStatus()
    {
        switch ($this->GetStatus()) {
            case IS_ACTIVE:
                $class = self::$STATUS_VALID;
                break;
            default:
                $class = self::$STATUS_INVALID;
                break;
        }

        return $class;
    }

    private function InstallVarProfiles(bool $reInstall = false)
    {
        if ($reInstall) {
            $this->SendDebug(__FUNCTION__, 'reInstall=' . $this->bool2str($reInstall), 0);
        }
    }

    public static $TIMEUNIT_SECONDS = 0;
    public static $TIMEUNIT_MINUTES = 1;
    public static $TIMEUNIT_HOURS = 2;

    private function GetTimeunitAsOptions()
    {
        return [
            [
                'value'   => self::$TIMEUNIT_SECONDS,
                'caption' => $this->Translate('Seconds'),
            ],
            [
                'value'   => self::$TIMEUNIT_MINUTES,
                'caption' => $this->Translate('Minutes'),
            ],
            [
                'value'   => self::$TIMEUNIT_HOURS,
                'caption' => $this->Translate('Hours'),
            ],
        ];
    }

    private function CalcByTimeunit(int $unit, int $val)
    {
        switch ($unit) {
            case self::$TIMEUNIT_SECONDS:
                $mul = 1;
                break;
            case self::$TIMEUNIT_MINUTES:
                $mul = 60;
                break;
            case self::$TIMEUNIT_HOURS:
                $mul = 60 * 60;
                break;
            default:
                $mul = 0;
                break;
        }
        return $val * $mul;
    }

    private function Timeunit2Suffix(int $unit)
    {
        switch ($unit) {
            case self::$TIMEUNIT_SECONDS:
                $s = 's';
                break;
            case self::$TIMEUNIT_MINUTES:
                $s = 'm';
                break;
            case self::$TIMEUNIT_HOURS:
                $s = 'h';
                break;
            default:
                $s = '';
                break;
        }
        return $s;
    }
}
