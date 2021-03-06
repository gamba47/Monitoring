#!/usr/bin/php -q
<?php
/* 
This plugin checks the DWD Web Output for a given Region 

 Author: Oliver Skibbe (oliskibbe (at) gmail.com)
 Web: http://oskibbe.blogspot.com / https://github.com/riskersen
 Date: 2015-04-21

 Changelog:
 Release 1.0 (2015-03-31)
 - initial release

 Release 1.1 (2015-04-01)
 - modified REGEX to support CRIT and WARN (UNWETTERWARNUNG > WARNUNG)
 - code clean up

 Release 1.2 (2015-04-21)
 - added ignore warning support

 Release 1.5 (2015-04-24)
 - added proxy support

*/

function stripos_array( $needle, $haystack ) {
	if ( !is_array( $haystack ) ) {
		if ( stripos( $needle, $haystack ) ) {
			return $element;
		}
	} else {
		foreach ( $haystack as $element ) {
			if ( stripos( $needle, $element ) ) {
				return $element;
			}
		}
	}

	return false;
}

if ( $argc <= 2 ) {
	help();
}


// Proxy Settings 
$proxy_url = "http://192.168.101.1:8080";
$proxy_user = "";
$proxy_pass = "";

// curl timeout settings
$connect_timeout = 5;
$timeout = 15;


// warnings which should be ignored
$ignore_warnung = Array( "WINDBÖEN", "NEBEL");

$region = strtoupper($argv[1]);
$region_name = $argv[2];

$url = "http://www.dwd.de/dyn/app/ws/html/reports/" . $region . "_warning_de.html";

$crit_regex = "@.*Es (sind|ist) (?<warnung_count>\d+) Warnung.*Amtliche UNWETTERWARNUNG (?<warnung>.*) </p>.*von: (?<von_datum>\w+, \d{2}\.\d{2}\.\d{4} \d{2}:\d{2} Uhr) </p>.*bis: (?<bis_datum>\w+, \d{2}\.\d{2}\.\d{4} \d{2}:\d{2} Uhr) </p>.*@isUm";
$warn_regex = "@.*Es (sind|ist) (?<warnung_count>\d+) Warnung.*Amtliche (?<warnung>.*) </p>.*von: (?<von_datum>\w+, \d{2}\.\d{2}\.\d{4} \d{2}:\d{2} Uhr) </p>.*bis: (?<bis_datum>\w+, \d{2}\.\d{2}\.\d{4} \d{2}:\d{2} Uhr) </p>.*@isUm";

$ok_string = "Es sind keine Warnungen";


$nagios_return = Array( 
			0 => "OK",
			1 => "WARNING",
			2 => "CRITICAL",
			3 => "UNKNOWN",
);

// create curl resource 
$ch = curl_init(); 

// set url 
curl_setopt($ch, CURLOPT_URL, $url); 

//return the transfer as a string 
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 

// curl connect timeout
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

// curl proxy settings
curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_PROXY, $proxy_url);    
curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_user . ":" . $proxy_pass);

// $dwd_output contains the output string 
$dwd_output = curl_exec($ch); 
$curl_errno = curl_errno($ch);

if ( $curl_errno != 0 || curl_error($ch) ) {
	$output = "CURL failed: Error no: " . $curl_errno . " error: " . curl_error($ch);
	$perf = "";
	$out_state = 3;

} else {
	// helper
	$matches = Array();

	// crit regex
	if ( preg_match($crit_regex, $dwd_output, $matches) ) {
		$out_state = 2;
	// warn regex
	} else if ( preg_match($warn_regex, $dwd_output, $matches) ) {
		$out_state = 1;
	// ok string check stristr should be more performant
	} else if ( stristr($dwd_output, $ok_string ) ) {
		$out_state = 0;
	// this should not never happen
	} else {
		$out_state = 3;
	}


	// ignore return
	$out_state = ( ( array_key_exists('warnung_count', $matches) && $matches['warnung_count'] !== '' ) && ! stripos_array( $matches['warnung'] , $ignore_warnung ) ) ? $out_state : 0;

	if ( $out_state > 2 ) {
		$output = "Kein gültiges Ergebnis gefunden. Bitte überprüfen Sie die URL " . $url . " und melden sich beim Autor des Plugins";

	} else if ( $out_state > 0 ) {
		$output = $matches['warnung_count'] . " Warnung(en) für " . $region_name . " gefunden, " . $matches['warnung'] . " von: " . $matches['von_datum'] . " bis: " . $matches['bis_datum'];
	} else {
		$output = "keine Warnungen für " . $region_name .  " auf " . $url . " gefunden";
	}
	
	$perf = sprintf("'aktive_warnungen'=%s", ( array_key_exists('warnung_count', $matches) && $matches['warnung_count'] !== '' ) ? $matches['warnung_count'] : 0 );
}

// close curl resource to free up system resources 
curl_close($ch);

echo $nagios_return[$out_state] . ": " . $output . PHP_EOL . "URL: " . $url . "|" . $perf;
exit($out_state);

function help() {
	global $argv;

        echo basename($argv[0]) . " region region_name 
\targ1\tDWD Region e.g. HAN
\targ2\tRegion name, for better looking output e.g. Hannover
";

        exit(3);
}

// EOF
