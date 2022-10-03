<?php

declare(strict_types=1);

$scriptName = IPS_GetName($_IPS['SELF']) . '(' . $_IPS['SELF'] . ')';

// Konfiguration
$region = 'NW';

$tstamp = isset($_IPS['TSTAMP']) ? intval($_IPS['TSTAMP']) : time();

$url = 'https://feiertage.jarmedia.de/api/?jahr=' . date('Y', $tstamp) . '&nur_land=' . $region;
$data = Sys_GetURLContent($url);
if ($data == '') {
    echo $scriptName . ': missing data from ' . $url . PHP_EOL;
    return -1;
}
$entries = json_decode($data, true);
if ($entries == '') {
    echo $scriptName . ': malformed data \"$data\" from ' . $url . PHP_EOL;
    return -1;
}

$curDay = date('Y-m-d', $tstamp);

$holidayName = '';
foreach ($entries as $name => $entry) {
    if ($entry['datum'] == $curDay) {
        $holidayName = $name;
        break;
    }
}
echo $holidayName;
