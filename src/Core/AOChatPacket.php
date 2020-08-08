<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;

/**
 * @author Oskari Saarenmaa <auno@auno.org>.
 * @license GPL
 *
 * A disassembly of the official java chat client[1] for Anarchy Online
 * and Slicer's AO::Chat perl module[2] were used as a reference for this
 * class.
 *
 * [1]: <http://www.anarchy-online.com/content/community/forumsandchat/>
 * [2]: <http://www.hackersquest.org/ao/>
 */

/**
 * The AOChatPacket class - turning packets into binary blobs and
 * binary blobs into packets.
 *
 * Data types:
 * I - 32 bit integer: uint32_t
 * S - 8 bit string array: uint16_t length, char str[length]
 * G - 40 bit binary data: unsigned char data[5]
 * i - integer array: uint16_t count, uint32_t[count]
 * s - string array: uint16_t count, aochat_str_t[count]
 *
 * D - 'data', we have relabeled all 'D' type fields to 'S'
 * M - mapping [see t.class in ao_nosign.jar] - unsupported
 *
 */

/* Packet type definitions - so we won't have to use the number IDs
* .. I did not distinct between server and client message types, as
* they are mostly the same for same type packets, but maybe it should
* have been done anyway..  // auno - 2004/mar/26
*/
define('AOCP_LOGIN_SEED',               0);
define('AOCP_LOGIN_REQUEST',            2);
define('AOCP_LOGIN_SELECT',             3);
define('AOCP_LOGIN_OK',                 5);
define('AOCP_LOGIN_ERROR',              6);
define('AOCP_LOGIN_CHARLIST',           7);
define('AOCP_CLIENT_UNKNOWN',          10);
define('AOCP_CLIENT_NAME',             20);
define('AOCP_CLIENT_LOOKUP',           21);
define('AOCP_MSG_PRIVATE',             30);
define('AOCP_MSG_VICINITY',            34);
define('AOCP_MSG_VICINITYA',           35);
define('AOCP_MSG_SYSTEM',              36);
define('AOCP_CHAT_NOTICE',             37);
define('AOCP_BUDDY_ADD',               40);
define('AOCP_BUDDY_REMOVE',            41);
define('AOCP_ONLINE_SET',              42);
define('AOCP_PRIVGRP_INVITE',          50);
define('AOCP_PRIVGRP_KICK',            51);
define('AOCP_PRIVGRP_JOIN',            52);
define('AOCP_PRIVGRP_PART',            53);
define('AOCP_PRIVGRP_KICKALL',         54);
define('AOCP_PRIVGRP_CLIJOIN',         55);
define('AOCP_PRIVGRP_CLIPART',         56);
define('AOCP_PRIVGRP_MESSAGE',         57);
define('AOCP_PRIVGRP_REFUSE',          58);
define('AOCP_GROUP_ANNOUNCE',          60);
define('AOCP_GROUP_PART',              61);
define('AOCP_GROUP_DATA_SET',          64);
define('AOCP_GROUP_MESSAGE',           65);
define('AOCP_GROUP_CM_SET',            66);
define('AOCP_CLIENTMODE_GET',          70);
define('AOCP_CLIENTMODE_SET',          71);
define('AOCP_PING',                   100);
define('AOCP_FORWARD',                110);
define('AOCP_CC',                     120);
define('AOCP_ADM_MUX_INFO',          1100);

define('AOCP_GROUP_JOIN',		AOCP_GROUP_ANNOUNCE); // compat

class AOChatPacket {
	/**
	 * @var array<string,array<int,array<string,string>>>
	 */
	private static array $packet_map = [
		"in" => [
			AOCP_LOGIN_SEED       => ["name" => "Login Seed",                  "args" => "S"],
			AOCP_LOGIN_OK         => ["name" => "Login Result OK",             "args" => ""],
			AOCP_LOGIN_ERROR      => ["name" => "Login Result Error",          "args" => "S"],
			AOCP_LOGIN_CHARLIST   => ["name" => "Login CharacterList",         "args" => "isii"],
			AOCP_CLIENT_UNKNOWN   => ["name" => "Client Unknown",              "args" => "I"],
			AOCP_CLIENT_NAME      => ["name" => "Client Name",                 "args" => "IS"],
			AOCP_CLIENT_LOOKUP    => ["name" => "Lookup Result",               "args" => "IS"],
			AOCP_MSG_PRIVATE      => ["name" => "Message Private",             "args" => "ISS"],
			AOCP_MSG_VICINITY     => ["name" => "Message Vicinity",            "args" => "ISS"],
			AOCP_MSG_VICINITYA    => ["name" => "Message Anon Vicinity",       "args" => "SSS"],
			AOCP_MSG_SYSTEM       => ["name" => "Message System",              "args" => "S"],
			AOCP_CHAT_NOTICE      => ["name" => "Chat Notice",                 "args" => "IIIS"],
			AOCP_BUDDY_ADD        => ["name" => "Buddy Added",                 "args" => "IIS"],
			AOCP_BUDDY_REMOVE     => ["name" => "Buddy Removed",               "args" => "I"],
			AOCP_PRIVGRP_INVITE   => ["name" => "Privategroup Invited",        "args" => "I"],
			AOCP_PRIVGRP_KICK     => ["name" => "Privategroup Kicked",         "args" => "I"],
			AOCP_PRIVGRP_PART     => ["name" => "Privategroup Part",           "args" => "I"],
			AOCP_PRIVGRP_CLIJOIN  => ["name" => "Privategroup Client Join",    "args" => "II"],
			AOCP_PRIVGRP_CLIPART  => ["name" => "Privategroup Client Part",    "args" => "II"],
			AOCP_PRIVGRP_MESSAGE  => ["name" => "Privategroup Message",        "args" => "IISS"],
			AOCP_PRIVGRP_REFUSE   => ["name" => "Privategroup Refuse Invite",  "args" => "II"],
			AOCP_GROUP_ANNOUNCE   => ["name" => "Group Announce",              "args" => "GSIS"],
			AOCP_GROUP_PART       => ["name" => "Group Part",                  "args" => "G"],
			AOCP_GROUP_MESSAGE    => ["name" => "Group Message",               "args" => "GISS"],
			AOCP_PING             => ["name" => "Pong",                        "args" => "S"],
			AOCP_FORWARD          => ["name" => "Forward",                     "args" => "IM"],
			AOCP_ADM_MUX_INFO     => ["name" => "Adm Mux Info",                "args" => "iii"],
		],
		"out" => [
			AOCP_LOGIN_REQUEST    => ["name" => "Login Response GetCharLst",   "args" => "ISS"],
			AOCP_LOGIN_SELECT     => ["name" => "Login Select Character",      "args" => "I"],
			AOCP_CLIENT_LOOKUP    => ["name" => "Name Lookup",                 "args" => "S"],
			AOCP_MSG_PRIVATE      => ["name" => "Message Private",             "args" => "ISS"],
			AOCP_BUDDY_ADD        => ["name" => "Buddy Add",                   "args" => "IS"],
			AOCP_BUDDY_REMOVE     => ["name" => "Buddy Remove",                "args" => "I"],
			AOCP_ONLINE_SET       => ["name" => "Onlinestatus Set",            "args" => "I"],
			AOCP_PRIVGRP_INVITE   => ["name" => "Privategroup Invite",         "args" => "I"],
			AOCP_PRIVGRP_KICK     => ["name" => "Privategroup Kick",           "args" => "I"],
			AOCP_PRIVGRP_JOIN     => ["name" => "Privategroup Join",           "args" => "I"],
			AOCP_PRIVGRP_PART     => ["name" => "Privategroup Part",           "args" => "I"],
			AOCP_PRIVGRP_KICKALL  => ["name" => "Privategroup Kickall",        "args" => ""],
			AOCP_PRIVGRP_MESSAGE  => ["name" => "Privategroup Message",        "args" => "ISS"],
			AOCP_GROUP_DATA_SET   => ["name" => "Group Data Set",              "args" => "GIS"],
			AOCP_GROUP_MESSAGE    => ["name" => "Group Message",               "args" => "GSS"],
			AOCP_GROUP_CM_SET     => ["name" => "Group Clientmode Set",        "args" => "GIIII"],
			AOCP_CLIENTMODE_GET   => ["name" => "Clientmode Get",              "args" => "IG"],
			AOCP_CLIENTMODE_SET   => ["name" => "Clientmode Set",              "args" => "IIII"],
			AOCP_PING             => ["name" => "Ping",                        "args" => "S"],
			AOCP_CC               => ["name" => "CC",                          "args" => "s"],
		]
	];

	/**
	 * The decoded arguments of the chat packet
	 */
	public array $args=[];

	/**
	 * The package type as in AOCP_LOGIN_REQUEST or AOCP_PRIVGROUP_JOIN
	 */
	public int $type;

	/**
	 * The direction of the packet (in or out)
	 */
	public string $dir;

	/**
	 * The encoded binary packet data
	 */
	public string $data;

	/**
	 * Create a new packet, either for parsing incoming or encoding outgoing ones
	 *
	 * @param string|mixed[] $data Either the data to decode (if $type == "in")
	 *                             or the data to encode(if $type == "out")
	 */
	public function __construct(string $dir, int $type, $data) {
		$this->args = array();
		$this->type = $type;
		$this->dir  = $dir;
		$pmap = self::$packet_map[$dir][$type];

		if (!$pmap) {
			throw new Exception("Unsupported packet type (". $dir . ", " . $type . ")");
			return false;
		}

		if ($dir == "in") {
			if (!is_string($data)) {
				throw new Exception("Incorrect argument for incoming packet, expecting a string.");
				return false;
			}

			for ($i = 0; $i < strlen($pmap["args"]); $i++) {
				$sa = $pmap["args"][$i];
				switch ($sa) {
					case "I":
						$res  = array_pop(unpack("N", $data));
						$data = substr($data, 4);
						break;

					case "S":
						$len  = array_pop(unpack("n", $data));
						$res  = substr($data, 2, $len);
						$data = substr($data, 2 + $len);
						break;

					case "G":
						$res  = substr($data, 0, 5);
						$data = substr($data, 5);
						break;

					case "i":
						$len  = array_pop(unpack("n", $data));
						$res  = array_values(unpack("N" . $len, substr($data, 2)));
						$data = substr($data, 2 + 4 * $len);
						break;

					case "s":
						$len  = array_pop(unpack("n", $data));
						$data = substr($data, 2);
						$res  = array();
						while ($len--) {
							$slen  = array_pop(unpack("n", $data));
							$res[] = substr($data, 2, $slen);
							$data  = substr($data, 2+$slen);
						}
						break;

					default:
						throw new Exception("Unknown argument type! (" . $sa . ")");
						continue(2);
				}
				$this->args[] = $res;
			}
		} else {
			if (!is_array($data)) {
				$args = array($data);
			} else {
				$args = $data;
			}
			$data = "";

			for ($i = 0; $i < strlen($pmap["args"]); $i++) {
				$sa = $pmap["args"][$i];
				$it = array_shift($args);

				if (is_null($it)) {
					throw new Exception("Missing argument for packet.");
					break;
				}

				switch ($sa) {
					case "I":
						$data .= pack("N", $it);
						break;

					case "S":
						$data .= pack("n", strlen($it)) . $it;
						break;

					case "G":
						$data .= $it;
						break;

					case "s":
						$data .= pack("n", count($it));
						foreach ($it as $it_elem) {
							$data .= pack("n", strlen($it_elem)) . $it_elem;
						}
						break;

					default:
						throw new Exception("Unknown argument type! (" . $sa . ")");
						continue(2);
				}
			}

			$this->data = $data;
		}
		return true;
	}
}
