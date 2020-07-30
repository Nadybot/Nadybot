<?php

namespace Nadybot\Core;

/**
 * AO xml abstaction layer for guild info, whois, player history and server status.
 *
 * @author Sebuda (RK2)
 * @author Derroylo (RK2)
 * @version: 1.1
 * @link http://sourceforge.net/projects/budabot
 *
 * Date(created): 01.10.2005
 * Date(last modified): 16.01.2007
 *
 * @copyright 2005, 2006, 2007 Carsten Lohmann and J. Gracik
 *
 * @license GPL
 */

//class provide some basic function to splice XML Files or getting an XML file from a URL
class XML {
	//Extracts one entry of the XML file
	public static function spliceData($sourcefile, $start, $end) {
		$data = explode($start, $sourcefile, 2);
		if (!$data || (is_array($data) && count($data) < 2)) {
			return "";
		}
		$data = $data[1];
		$data = explode($end, $data, 2);
		if (!$data || (is_array($data) && count($data) < 2)) {
			return "";
		}
		return $data[0];
	}

	//Extracts more then one entry of the XML file
	public static function spliceMultiData($sourcefile, $start, $end) {
		$targetdata = array();
		$sourcedata = explode($start, $sourcefile);
		array_shift($sourcedata);
		foreach ($sourcedata as $indsplit) {
			$target = explode($end, $indsplit, 2);
			$targetdata[] = $target[0];
		}
		return $targetdata;
	}
}
