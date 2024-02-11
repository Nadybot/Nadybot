<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\json_encode;

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
 */

/* Packet type definitions - so we won't have to use the number IDs
 *  I did not distinct between server and client message types, as
 * they are mostly the same for same type packets, but maybe it should
 * have been done anyway..  // auno - 2004/mar/26
 */

class AOChatPacket implements Loggable {
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
	 * The decoded arguments of the chat packet
	 *
	 * @var mixed[]
	 */
	public array $args=[];

	/** The package type as in LOGIN_REQUEST or PRIVGROUP_JOIN */
	public int $type;

	/** The direction of the packet (in or out) */
	public string $dir;

	/** The encoded binary packet data */
	public string $data;

	/** @var array<string,array<int,array<string,string>>> */
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
		],
	];

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
		}

		if ($dir == "in") {
			$this->data = $data;
			if (!is_string($data)) {
				throw new Exception("Incorrect argument for incoming packet, expecting a string.");
			}

			for ($i = 0; $i < strlen($pmap["args"]); $i++) {
				$sa = $pmap["args"][$i];
				switch ($sa) {
					case "I":
						$unp  = \Safe\unpack("N", $data);
						if (!is_array($unp)) {
							throw new Exception("Invalid packet data received.");
						}
						$res  = array_pop($unp);
						$data = substr($data, 4);
						break;

					case "S":
						$unp  = \Safe\unpack("n", $data);
						if (!is_array($unp)) {
							throw new Exception("Invalid packet data received.");
						}
						$len  = array_pop($unp);
						$res  = substr($data, 2, $len);
						$data = substr($data, 2 + $len);
						break;

					case "G":
						$res  = substr($data, 0, 5);
						$data = substr($data, 5);
						break;

					case "i":
						$unp  = \Safe\unpack("n", $data);
						if (!is_array($unp)) {
							throw new Exception("Invalid packet data received.");
						}
						$len  = array_pop($unp);
						$unp = \Safe\unpack("N" . $len, substr($data, 2));
						if (!is_array($unp)) {
							throw new Exception("Invalid packet data received.");
						}
						$res  = array_values($unp);
						$data = substr($data, 2 + 4 * $len);
						break;

					case "s":
						$unp  = \Safe\unpack("n", $data);
						if (!is_array($unp)) {
							throw new Exception("Invalid packet data received.");
						}
						$len  = array_pop($unp);
						$data = substr($data, 2);
						$res  = [];
						while ($len--) {
							$unp   = \Safe\unpack("n", $data);
							if (!is_array($unp)) {
								throw new Exception("Invalid packet data received.");
							}
							$slen  = array_pop($unp);
							$res[] = substr($data, 2, $slen);
							$data  = substr($data, 2+$slen);
						}
						break;

					default:
						throw new Exception("Unknown argument type! (" . $sa . ")");
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
				}

				switch ($sa) {
					case "I":
						$data .= \Safe\pack("N", $it);
						break;

					case "S":
						$data .= \Safe\pack("n", strlen((string)$it)) . $it;
						break;

					case "G":
						$data .= $it;
						break;

					case "s":
						$data .= \Safe\pack("n", count($it));
						foreach ($it as $it_elem) {
							$data .= \Safe\pack("n", strlen($it_elem)) . $it_elem;
						}
						break;

					default:
						throw new Exception("Unknown argument type! (" . $sa . ")");
				}
			}

			$this->data = $data;
		}
	}

	public function typeToName(int $type): ?string {
		$types = [
			0 => "LOGIN_SEED",
			2 => "LOGIN_REQUEST",
			3 => "LOGIN_SELECT",
			5 => "LOGIN_OK",
			6 => "LOGIN_ERROR",
			7 => "LOGIN_CHARLIST",
			10 => "CLIENT_UNKNOWN",
			20 => "CLIENT_NAME",
			21 => "CLIENT_LOOKUP",
			30 => "MSG_PRIVATE",
			34 => "MSG_VICINITY",
			35 => "MSG_VICINITYA",
			36 => "MSG_SYSTEM",
			37 => "CHAT_NOTICE",
			40 => "BUDDY_ADD",
			41 => "BUDDY_REMOVE",
			42 => "ONLINE_SET",
			50 => "PRIVGRP_INVITE",
			51 => "PRIVGRP_KICK",
			52 => "PRIVGRP_JOIN",
			53 => "PRIVGRP_PART",
			54 => "PRIVGRP_KICKALL",
			55 => "PRIVGRP_CLIJOIN",
			56 => "PRIVGRP_CLIPART",
			57 => "PRIVGRP_MESSAGE",
			58 => "PRIVGRP_REFUSE",
			60 => "GROUP_ANNOUNCE",
			61 => "GROUP_PART",
			64 => "GROUP_DATA_SET",
			65 => "GROUP_MESSAGE",
			66 => "GROUP_CM_SET",
			70 => "CLIENTMODE_GET",
			71 => "CLIENTMODE_SET",
			100 => "PING",
			110 => "FORWARD",
			120 => "CC",
			1100 => "ADM_MUX_INFO",
		];
		return $types[$type] ?? null;
	}

	public function toString(): string {
		$args = [];
		foreach ($this->args as $arg) {
			if (!is_string($arg)) {
				$args []= json_encode($arg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
				continue;
			}
			$bin = '"';
			for ($i = 0; $i < strlen($arg); $i++) {
				$ord = ord($arg[$i]);
				switch ($ord) {
					case 9: // <tab>
						$bin .= "\\t";
						break;
					case 10: // <newline>
						$bin .= "\\n";
						break;
					case 34: // "
						$bin .= "\\\"";
						break;
					case 92: // \
						$bin .= "\\\\";
						break;
					default:
						if ($ord < 32 || $ord > 127) {
							$bin .= "\\x" . sprintf("%02X", $ord);
						} else {
							$bin .= $arg[$i];
						}
				}
			}
			$bin .= '"';
			$args []= $bin;
		}
		$data = \Safe\pack("n2", $this->type, strlen($this->data)) . $this->data;
		return "<AoChatPacket>{".
			"type=" . ($this->typeToName($this->type)??"Unknown") . ",".
			"data=0x" . join("", str_split(bin2hex($data), 2)) . ",".
			"args=[" . join(",", $args) . "],".
			"dir=" . json_encode($this->dir, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE) . "}";
	}
}
