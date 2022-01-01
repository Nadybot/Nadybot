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
	public const LOGIN_SEED =         0;
	public const LOGIN_REQUEST =      2;
	public const LOGIN_SELECT =       3;
	public const LOGIN_OK =           5;
	public const LOGIN_ERROR =        6;
	public const LOGIN_CHARLIST =     7;
	public const CLIENT_UNKNOWN =    10;
	public const CLIENT_NAME =       20;
	public const CLIENT_LOOKUP =     21;
	public const MSG_PRIVATE =       30;
	public const MSG_VICINITY =      34;
	public const MSG_VICINITYA =     35;
	public const MSG_SYSTEM =        36;
	public const CHAT_NOTICE =       37;
	public const BUDDY_ADD =         40;
	public const BUDDY_REMOVE =      41;
	public const ONLINE_SET =        42;
	public const PRIVGRP_INVITE =    50;
	public const PRIVGRP_KICK =      51;
	public const PRIVGRP_JOIN =      52;
	public const PRIVGRP_PART =      53;
	public const PRIVGRP_KICKALL =   54;
	public const PRIVGRP_CLIJOIN =   55;
	public const PRIVGRP_CLIPART =   56;
	public const PRIVGRP_MESSAGE =   57;
	public const PRIVGRP_REFUSE =    58;
	public const GROUP_ANNOUNCE =    60;
	public const GROUP_PART =        61;
	public const GROUP_DATA_SET =    64;
	public const GROUP_MESSAGE =     65;
	public const GROUP_CM_SET =      66;
	public const CLIENTMODE_GET =    70;
	public const CLIENTMODE_SET =    71;
	public const PING =             100;
	public const FORWARD =          110;
	public const CC =               120;
	public const ADM_MUX_INFO =    1100;

	/**
	 * @var array<string,array<int,array<string,string>>>
	 */
	private static array $packet_map = [
		"in" => [
			self::LOGIN_SEED       => ["name" => "Login Seed",                  "args" => "S"],
			self::LOGIN_OK         => ["name" => "Login Result OK",             "args" => ""],
			self::LOGIN_ERROR      => ["name" => "Login Result Error",          "args" => "S"],
			self::LOGIN_CHARLIST   => ["name" => "Login CharacterList",         "args" => "isii"],
			self::CLIENT_UNKNOWN   => ["name" => "Client Unknown",              "args" => "I"],
			self::CLIENT_NAME      => ["name" => "Client Name",                 "args" => "IS"],
			self::CLIENT_LOOKUP    => ["name" => "Lookup Result",               "args" => "IS"],
			self::MSG_PRIVATE      => ["name" => "Message Private",             "args" => "ISS"],
			self::MSG_VICINITY     => ["name" => "Message Vicinity",            "args" => "ISS"],
			self::MSG_VICINITYA    => ["name" => "Message Anon Vicinity",       "args" => "SSS"],
			self::MSG_SYSTEM       => ["name" => "Message System",              "args" => "S"],
			self::CHAT_NOTICE      => ["name" => "Chat Notice",                 "args" => "IIIS"],
			self::BUDDY_ADD        => ["name" => "Buddy Added",                 "args" => "IIS"],
			self::BUDDY_REMOVE     => ["name" => "Buddy Removed",               "args" => "I"],
			self::PRIVGRP_INVITE   => ["name" => "Privategroup Invited",        "args" => "I"],
			self::PRIVGRP_KICK     => ["name" => "Privategroup Kicked",         "args" => "I"],
			self::PRIVGRP_PART     => ["name" => "Privategroup Part",           "args" => "I"],
			self::PRIVGRP_CLIJOIN  => ["name" => "Privategroup Client Join",    "args" => "II"],
			self::PRIVGRP_CLIPART  => ["name" => "Privategroup Client Part",    "args" => "II"],
			self::PRIVGRP_MESSAGE  => ["name" => "Privategroup Message",        "args" => "IISS"],
			self::PRIVGRP_REFUSE   => ["name" => "Privategroup Refuse Invite",  "args" => "II"],
			self::GROUP_ANNOUNCE   => ["name" => "Group Announce",              "args" => "GSIS"],
			self::GROUP_PART       => ["name" => "Group Part",                  "args" => "G"],
			self::GROUP_MESSAGE    => ["name" => "Group Message",               "args" => "GISS"],
			self::PING             => ["name" => "Pong",                        "args" => "S"],
			self::FORWARD          => ["name" => "Forward",                     "args" => "IM"],
			self::ADM_MUX_INFO     => ["name" => "Adm Mux Info",                "args" => "iii"],
		],
		"out" => [
			self::LOGIN_REQUEST    => ["name" => "Login Response GetCharLst",   "args" => "ISS"],
			self::LOGIN_SELECT     => ["name" => "Login Select Character",      "args" => "I"],
			self::CLIENT_LOOKUP    => ["name" => "Name Lookup",                 "args" => "S"],
			self::MSG_PRIVATE      => ["name" => "Message Private",             "args" => "ISS"],
			self::BUDDY_ADD        => ["name" => "Buddy Add",                   "args" => "IS"],
			self::BUDDY_REMOVE     => ["name" => "Buddy Remove",                "args" => "I"],
			self::ONLINE_SET       => ["name" => "Onlinestatus Set",            "args" => "I"],
			self::PRIVGRP_INVITE   => ["name" => "Privategroup Invite",         "args" => "I"],
			self::PRIVGRP_KICK     => ["name" => "Privategroup Kick",           "args" => "I"],
			self::PRIVGRP_JOIN     => ["name" => "Privategroup Join",           "args" => "I"],
			self::PRIVGRP_PART     => ["name" => "Privategroup Part",           "args" => "I"],
			self::PRIVGRP_KICKALL  => ["name" => "Privategroup Kickall",        "args" => ""],
			self::PRIVGRP_MESSAGE  => ["name" => "Privategroup Message",        "args" => "ISS"],
			self::GROUP_DATA_SET   => ["name" => "Group Data Set",              "args" => "GIS"],
			self::GROUP_MESSAGE    => ["name" => "Group Message",               "args" => "GSS"],
			self::GROUP_CM_SET     => ["name" => "Group Clientmode Set",        "args" => "GIIII"],
			self::CLIENTMODE_GET   => ["name" => "Clientmode Get",              "args" => "IG"],
			self::CLIENTMODE_SET   => ["name" => "Clientmode Set",              "args" => "IIII"],
			self::PING             => ["name" => "Ping",                        "args" => "S"],
			self::CC               => ["name" => "CC",                          "args" => "s"],
		]
	];

	/**
	 * The decoded arguments of the chat packet
	 */
	public array $args=[];

	/**
	 * The package type as in LOGIN_REQUEST or PRIVGROUP_JOIN
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
	 * @param mixed $data Either the data to decode (if $type == "in")
	 *                    or the data to encode(if $type == "out")
	 */
	public function __construct(string $dir, int $type, mixed $data) {
		$this->args = [];
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
						$unp  = unpack("N", $data);
						$res  = array_pop($unp);
						$data = substr($data, 4);
						break;

					case "S":
						$unp  = unpack("n", $data);
						$len  = array_pop($unp);
						$res  = substr($data, 2, $len);
						$data = substr($data, 2 + $len);
						break;

					case "G":
						$res  = substr($data, 0, 5);
						$data = substr($data, 5);
						break;

					case "i":
						$unp  = unpack("n", $data);
						$len  = array_pop($unp);
						$res  = array_values(unpack("N" . $len, substr($data, 2)));
						$data = substr($data, 2 + 4 * $len);
						break;

					case "s":
						$unp  = unpack("n", $data);
						$len  = array_pop($unp);
						$data = substr($data, 2);
						$res  = [];
						while ($len--) {
							$unp   = unpack("n", $data);
							$slen  = array_pop($unp);
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
				$args = [$data];
			} else {
				$args = $data;
			}
			$this->args = $args;
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
						$data .= pack("n", strlen((string)$it)) . $it;
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
	}
}
