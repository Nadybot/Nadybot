<?php

namespace Budabot\Core;

/*
* $Id: aochat.php,v 1.1 2006/12/08 15:17:54 genesiscl Exp $
*
* Modified to handle the recent problem with the integer overflow
*
* Copyright (C) 2002-2005  Oskari Saarenmaa <auno@auno.org>.
*
* AOChat, a PHP class for talking with the Anarchy Online chat servers.
* It requires the sockets extension (to connect to the chat server..)
* from PHP 4.2.0+ and the BCMath extension (for generating
* and calculating the login keys) to work.
*
* A disassembly of the official java chat client[1] for Anarchy Online
* and Slicer's AO::Chat perl module[2] were used as a reference for this
* class.
*
* [1]: <http://www.anarchy-online.com/content/community/forumsandchat/>
* [2]: <http://www.hackersquest.org/ao/>
*
* Updates to this class can be found from the following web site:
*   http://auno.org/dev/aochat.html
*
**************************************************************************
*
* This program is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation; either version 2 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful, but
* WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
* General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program; if not, write to the Free Software
* Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307
* USA
*
*/

/**
 * Ignore non-camelCaps named methods as a lot of external calls rely on
 * them and we can't simply rename them
 *
 * phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
 */

if ((float)phpversion() < 5.0) {
	die("AOChat class needs PHP version 5.0.0 or higher in order to work.\n");
}

if (!extension_loaded("sockets")) {
	die("AOChat class needs the Sockets extension to work.\n");
}

if (!extension_loaded("bcmath")) {
	die("AOChat class needs the BCMath extension to work.\n");
}

set_time_limit(0);
ini_set("html_errors", 0);

define('AOC_GROUP_NOWRITE',     0x00000002);
define('AOC_GROUP_NOASIAN',     0x00000020);
define('AOC_GROUP_MUTE',        0x01010000);
define('AOC_GROUP_LOG',         0x02020000);

define('AOC_FLOOD_LIMIT',                7);
define('AOC_FLOOD_INC',                  2);

define('AOEM_UNKNOWN',                0xFF);
define('AOEM_ORG_JOIN',               0x10);
define('AOEM_ORG_KICK',               0x11);
define('AOEM_ORG_LEAVE',              0x12);
define('AOEM_ORG_DISBAND',            0x13);
define('AOEM_ORG_FORM',               0x14);
define('AOEM_ORG_VOTE',               0x15);
define('AOEM_ORG_STRIKE',             0x16);
define('AOEM_NW_ATTACK',              0x20);
define('AOEM_NW_ABANDON',             0x21);
define('AOEM_NW_OPENING',             0x22);
define('AOEM_NW_TOWER_ATT_ORG',       0x23);
define('AOEM_NW_TOWER_ATT',           0x24);
define('AOEM_NW_TOWER',               0x25);
define('AOEM_AI_CLOAK',               0x30);
define('AOEM_AI_RADAR',               0x31);
define('AOEM_AI_ATTACK',              0x32);
define('AOEM_AI_REMOVE_INIT',         0x33);
define('AOEM_AI_REMOVE',              0x34);
define('AOEM_AI_HQ_REMOVE_INIT',      0x35);
define('AOEM_AI_HQ_REMOVE',           0x36);

class AOChat {
	/**
	 * A lookup cache for character name => id and id => character name
	 *
	 * @var (string|int)[] $id
	 */
	public $id;

	/**
	 * A lookup cache for group name => id and id => group name
	 *
	 * @var (string|int)[] $gid
	 */
	public $gid;

	/**
	 * A cache for character information
	 *
	 * Stored as array(
	 * 	"id"     => character id
	 * 	"name"   => character name
	 * 	"level"  => character level
	 * 	"online" => true if online
	 * )
	 * @var array $chars
	 */
	public $chars;

	/**
	 * The currently logged in character or null if not logged in
	 *
	 * @var string $char
	 */
	public $char;

	/**
	 * An associative array where each group's status (muted, etc) is tracked
	 *
	 * Stored as array(
	 * 	group ip => group status
	 * )
	 *
	 * @var int[] $grp
	 */
	public $grp;

	/**
	 * Doesn't seem to be in use anymore
	 *
	 * @todo Check if can be removed
	 * @var mixed $buddies
	 */
	public $buddies;

	/**
	 * The socket with which we are connected to the chat server
	 *
	 * @var resource $socket
	 */
	public $socket;

	/**
	 * Timestamp when the last package was received
	 *
	 * @var int $last_packet
	 */
	public $last_packet;

	/**
	 * Timestamp when we sent the last ping
	 *
	 * @var int $last_ping
	 */
	public $last_ping;

	/**
	 * The chat queue
	 *
	 * @var \Budabot\Core\AOChatQueue $chatqueue
	 */
	public $chatqueue;

	/**
	 * The parser for the MMDB
	 *
	 * @var \Budabot\Core\MMDBParser $mmdbParser
	 */
	public $mmdbParser;

	/** @var \Budabot\Core\LoggerWrapper $logger */
	public $logger;

	/**
	 * Constructor method
	 */
	public function __construct() {
		$this->disconnect();
		$this->mmdbParser = new MMDBParser('data/text.mdb');
		$this->logger = new LoggerWrapper('AOChat');
	}

	/**
	 * Disconnect from the chat server (if connected) and init varaibles
	 *
	 * @return void
	 */
	public function disconnect() {
		if (is_resource($this->socket)) {
			socket_close($this->socket);
		}
		$this->socket      = null;
		$this->char        = null;
		$this->last_packet = 0;
		$this->last_ping   = 0;
		$this->id          = array();
		$this->gid         = array();
		$this->grp         = array();
		$this->chars       = array();
		$this->chatqueue   = null;
	}

	/**
	 * Connect to the chatserver $server on port $port
	 *
	 * @param string $server
	 * @param int $port
	 * @return resource|false false if we cannot connect, otherwise the connected socket
	 */
	public function connect($server, $port) {
		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if (!is_resource($this->socket)) { /* this is fatal */
			$this->socket = null;
			$this->logger->log('error', "Could not create socket");
			die();
		}

		// prevents bot from hanging on startup when chatserver does not send login seed
		$timeout = 10;
		socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $timeout, 'usec' => 0));

		if (@socket_connect($this->socket, $server, $port) === false) {
			$this->logger->log('error', "Could not connect to the AO Chat server ($server:$port): " . trim(socket_strerror(socket_last_error($this->socket))));
			$this->disconnect();
			return false;
		}

		$this->chatqueue = new AOChatQueue(AOC_FLOOD_LIMIT, AOC_FLOOD_INC);

		return $this->socket;
	}

	/**
	 * Send all messages from the chat queue and a ping if necessary
	 *
	 * @return void
	 */
	public function iteration() {
		$now = time();

		if ($this->chatqueue !== null) {
			$packet = $this->chatqueue->getNext();
			while ($packet !== null) {
				$this->sendPacket($packet);
				$packet = $this->chatqueue->getNext();
			}
		}

		if (($now - $this->last_packet) > 60 && ($now - $this->last_ping) > 60) {
			$this->sendPing();
		}
	}

	/**
	 * Empty our chat queue and wait up to $time seconds for a packet
	 *
	 * Returns the packet if one arrived or null if none arrived in $time seconds.
	 *
	 * @param integer $time The  amount of seconds to wait for
	 * @return \Budabot\Core\AOChatPacket|false|null The recived package or null if none arrived or false if we couldn't parse it
	 */
	public function waitForPacket($time=1) {
		$this->iteration();

		$sec = (int)$time;
		if (is_float($time)) {
			$usec = (int)($time * 1000000 % 1000000);
		} else {
			$usec = 0;
		}

		if (!socket_select($a = array($this->socket), $b = null, $c = null, $sec, $usec)) {
			return null;
		} else {
			return $this->getPacket();
		}
	}

	/**
	 * Read $len bytes from the socket
	 *
	 * @param int $len How many bytes to read
	 * @return string The read data
	 */
	public function readData($len) {
		$data = "";
		$rlen = $len;
		while ($rlen > 0) {
			if (($tmp = socket_read($this->socket, $rlen)) === false) {
				$last_error = socket_strerror(socket_last_error($this->socket));
				$this->logger->log('error', "Read error: $last_error");
				die();
			}
			if ($tmp == "") {
				$this->logger->log('error', "Read error: EOF - (Someone else logging on to same account?)");
				die();
			}
			$data .= $tmp;
			$rlen -= strlen($tmp);
		}
		return $data;
	}

	/**
	 * Read a packet from the socket
	 *
	 * @return \Budabot\Core\AOChatPacket|false false if we were unable to read the packet's length, otherwise the packet
	 */
	public function getPacket() {
		$head = $this->readData(4);
		if (strlen($head) != 4) {
			return false;
		}

		list(, $type, $len) = unpack("n2", $head);

		$data = $this->readData($len);

		$packet = new AOChatPacket("in", $type, $data);
		
		if ($this->logger->isEnabledFor('debug')) {
			$this->logger->log('debug', print_r($packet, true));
		}

		switch ($type) {
			case AOCP_CLIENT_NAME:
			case AOCP_CLIENT_LOOKUP:
				list($id, $name) = $packet->args;
				$id = "" . $id;
				$name = ucfirst(strtolower($name));
				$this->id[$id]   = $name;
				$this->id[$name] = $id;
				break;

			case AOCP_GROUP_ANNOUNCE:
				list($gid, $name, $status) = $packet->args;
				$this->grp[$gid] = $status;
				$this->gid[$gid] = $name;
				$this->gid[strtolower($name)] = $gid;
				break;

			case AOCP_GROUP_MESSAGE:
				/* Hack to support extended messages */
				if ($packet->args[1] === 0 && substr($packet->args[2], 0, 2) == "~&") {
					$packet->args[2] = $this->readExtMsg($packet->args[2]);
				}
				break;

			case AOCP_CHAT_NOTICE:
				$category_id = 20000;
				$packet->args[4] = $this->mmdbParser->getMessageString($category_id, $packet->args[2]);
				if ($packet->args[4] !== null) {
					$packet->args[5] = $this->parseExtParams($packet->args[3]);
					if ($packet->args[5] !== null) {
						$packet->args[6] = vsprintf($packet->args[4], $packet->args[5]);
					} else {
						$this->logger->log('error', "Could not parse chat notice: " . print_r($packet, true));
					}
				}
				break;
		}

		$this->last_packet = time();

		return $packet;
	}

	/**
	 * Send a packet
	 *
	 * @param \Budabot\Core\AOChatPacket $packet The packet to send
	 * @return true
	 */
	public function sendPacket($packet) {
		$data = pack("n2", $packet->type, strlen($packet->data)) . $packet->data;
		
		$this->logger->log('debug', $data);

		socket_write($this->socket, $data, strlen($data));
		return true;
	}

	/**
	 * Login with an account to the server
	 *
	 * @param string $username The username to use
	 * @param string $password The password to use
	 * @return array|false false in case of error, otherwise our stored character information
	 */
	public function authenticate($username, $password) {
		$packet = $this->getPacket();
		if ($packet->type != AOCP_LOGIN_SEED) {
			return false;
		}
		$serverseed = $packet->args[0];

		$key = $this->generateLoginKey($serverseed, $username, $password);
		$pak = new AOChatPacket("out", AOCP_LOGIN_REQUEST, array(0, $username, $key));
		$this->sendPacket($pak);
		$packet = $this->getPacket();
		if ($packet->type != AOCP_LOGIN_CHARLIST) {
			return false;
		}

		for ($i = 0; $i < count($packet->args[0]); $i++) {
			$this->chars[] = array(
			"id"     => $packet->args[0][$i],
			"name"   => ucfirst(strtolower($packet->args[1][$i])),
			"level"  => $packet->args[2][$i],
			"online" => $packet->args[3][$i]);
		}

		$this->username = $username;

		return $this->chars;
	}

	/**
	 * Chose the character to login with
	 *
	 * @param string $char name of the character to login
	 * @return bool true on success, false on error
	 */
	public function login($char) {
		if (is_int($char)) {
			$field = "id";
		} elseif (is_string($char)) {
			$field = "name";
			$char  = ucfirst(strtolower($char));
		}

		if (!is_array($char)) {
			if (empty($field)) {
				return false;
			} else {
				foreach ($this->chars as $e) {
					if ($e[$field] == $char) {
						$char = $e;
						break;
					}
				}
			}
		}

		if (!is_array($char)) {
			$this->logger->log('error', "AOChat: no valid character to login");
			return false;
		}

		$loginSelect = new AOChatPacket("out", AOCP_LOGIN_SELECT, $char["id"]);
		$this->sendPacket($loginSelect);
		$packet = $this->getPacket();
		if ($packet->type != AOCP_LOGIN_OK) {
			return false;
		}

		$this->char  = $char;

		return true;
	}

	/**
	 * Lookup the user id for a username
	 *
	 * @param string $u
	 * @return int|false The user id or false if not found
	 */
	public function lookup_user($u) {
		$u = ucfirst(strtolower($u));

		if ($u == '') {
			return false;
		}

		if (isset($this->id[$u])) {
			return $this->id[$u];
		}

		$this->sendPacket(new AOChatPacket("out", AOCP_CLIENT_LOOKUP, $u));
		for ($i = 0; $i < 100 && !isset($this->id[$u]); $i++) {
			// hack so that packets are not discarding while waiting for char id response
			$packet = $this->waitForPacket(1);
			if ($packet) {
				$this->process_packet($packet);
			}
		}

		return isset($this->id[$u]) ? $this->id[$u] : false;
	}

	/**
	 * Get the user id of a username and handle special cases, such as $user already being a user id.
	 *
	 * @param string $user The name of the user to lookup
	 * @return int|false false on error, otherwise the UID
	 */
	public function get_uid($user) {
		if ($this->isReallyNumeric($user)) {
			return $this->fixunsigned($user);
		}

		$uid = $this->lookup_user($user);

		if ($uid === false || $uid == 0 || $uid == -1 || $uid == 0xffffffff || !$this->isReallyNumeric($uid)) {
			return false;
		}

		return $uid;
	}

	/**
	 * Fix overflows bits for unsigned numbers returned signed
	 *
	 * @param int $num The number to fix
	 * @return int
	 */
	public function fixunsigned($num) {
		if ($this->isReallyNumeric($num) && bcdiv("" . $num, "2147483648", 0)) {
			$num2 = -1 * bcsub("4294967296", "" . $num);
			return (int)$num2;
		}

		return (int)$num;
	}

	/**
	 * Check if $num only consists of digits
	 *
	 * @param string|int $num The number/string to check
	 * @return bool
	 */
	public function isReallyNumeric($num) {
		if (preg_match("/^([0-9\-]+)$/", "" . $num)) {
			return true;
		}

		return false;
	}

	/**
	 * Lookup the group id of a group
	 *
	 * @param string $arg Name of the group
	 * @param int $type If set to 1, check if $arg already is a group id
	 * @return int|false Either the group id or false if not found
	 */
	public function lookup_group($arg, $type=0) {
		if ($type && ($is_gid = (strlen($arg) === 5 && (ord($arg[0])&~0x80) < 0x10))) {
			return $arg;
		}
		if (!$is_gid) {
			$arg = strtolower($arg);
		}
		return isset($this->gid[$arg]) ? $this->gid[$arg] : false;
	}

	/**
	 * Get the group id of a group
	 *
	 * @param string $arg Name of the group
	 * @return int|false Either the group id or false if not found
	 */
	public function get_gid($g) {
		return $this->lookup_group($g, 1);
	}

	/**
	 * Get the group name of a group id
	 *
	 * @param int $g The group id
	 * @return string|false The group name or false if not found
	 */
	public function get_gname($g) {
		if (($gid = $this->lookup_group($g, 1)) === false) {
			return false;
		}
		return $this->gid[$gid];
	}

	/**
	 * Send a ping packet to keep the connection open
	 *
	 * @return true
	 */
	public function sendPing() {
		$this->last_ping = time();
		return $this->sendPacket(new AOChatPacket("out", AOCP_PING, "AOChat.php"));
	}

	/**
	 * Send a tell to a user
	 *
	 * @param string|int $user     user name or user id
	 * @param string     $msg      The message to send
	 * @param string     $blob     Ignored
	 * @param int        $priority The optional priority or medium if not set
	 * @return bool false if the user doesn't exist, true otherwise
	 */
	public function send_tell($user, $msg, $blob="\0", $priority=null) {
		if (($uid = $this->get_uid($user)) === false) {
			return false;
		}
		if ($priority == null) {
			$priority = AOC_PRIORITY_MED;
		}
		$this->chatqueue->push($priority, new AOChatPacket("out", AOCP_MSG_PRIVATE, array($uid, $msg, "\0")));
		$this->iteration();
		return true;
	}

	/**
	 * Send a message to the guild channel
	 *
	 * @param string $msg      The message to send
	 * @param string $blob     Ignored
	 * @param int    $priority The optional priority or medium if not set
	 * @return bool false if the guild channel doesn't exist, true otherwise
	 */
	public function send_guild($msg, $blob="\0", $priority=null) {
		$guild_gid = false;
		foreach ($this->grp as $gid => $status) {
			if (ord(substr($gid, 0, 1)) == 3) {
				$guild_gid = $gid;
				break;
			}
		}
		if (!$guild_gid) {
			return false;
		}
		if ($priority == null) {
			$priority = AOC_PRIORITY_MED;
		}
		$this->chatqueue->push($priority, new AOChatPacket("out", AOCP_GROUP_MESSAGE, array($guild_gid, $msg, "\0")));
		$this->iteration();
		return true;
	}

	/**
	 * Send a message to a channel
	 *
	 * @param int|string $group    The channel id or channel name to send to
	 * @param string     $msg      The message to send
	 * @param string     $blob     Ignored
	 * @param int        $priority The optional priority or medium if not set
	 * @return bool false if the channel doesn't exist, true otherwise
	 */
	public function send_group($group, $msg, $blob="\0", $priority=null) {
		if (($gid = $this->get_gid($group)) === false) {
			return false;
		}
		if ($priority == null) {
			$priority = AOC_PRIORITY_MED;
		}
		$this->chatqueue->push(AOC_PRIORITY_MED, new AOChatPacket("out", AOCP_GROUP_MESSAGE, array($gid, $msg, "\0")));
		$this->iteration();
		return true;
	}

	/**
	 * Join a channel
	 *
	 * @param int|string $group Channel id or channle name to join
	 * @return boolean False if the group doesn't exist, otherwise true
	 */
	public function group_join($group) {
		if (($gid = $this->get_gid($group)) === false) {
			return false;
		}

		return $this->sendPacket(new AOChatPacket("out", AOCP_GROUP_DATA_SET, array($gid, $this->grp[$gid] & ~AOC_GROUP_MUTE, "\0")));
	}

	/**
	 * Leave a channel
	 *
	 * @param int|string $group Channel id or channel name to leave
	 * @return boolean False if the group doesn't exist, otherwise true
	 */
	public function group_leave($group) {
		if (($gid = $this->get_gid($group)) === false) {
			return false;
		}

		return $this->sendPacket(new AOChatPacket("out", AOCP_GROUP_DATA_SET, array($gid, $this->grp[$gid] | AOC_GROUP_MUTE, "\0")));
	}

	/**
	 * Get a channel's status (log, more, noasian, nowrite)
	 *
	 * @param int|string $group The group id or group name
	 * @return int|false False if the group doesn't exist, a bitfield with status flags otherwise
	 */
	public function group_status($group) {
		if (($gid = $this->get_gid($group)) === false) {
			return false;
		}

		return $this->grp[$gid];
	}

	/**
	 * Send a message to a private group
	 *
	 * @param int|string $group The group id or group name to send to
	 * @param string     $msg   The message to send
	 * @param string     $blob  Ignored
	 * @return bool false if the channel doesn't exist, true otherwise
	 */
	public function send_privgroup($group, $msg, $blob="\0") {
		if (($gid = $this->get_uid($group)) === false) {
			return false;
		}

		return $this->sendPacket(new AOChatPacket("out", AOCP_PRIVGRP_MESSAGE, array($gid, $msg, $blob)));
	}

	/**
	 * Join a private group
	 *
	 * @param int|string $group group id or group name to join
	 * @return boolean False if the group doesn't exist, otherwise true
	 */
	public function privategroup_join($group) {
		if (($gid = $this->get_uid($group)) === false) {
			return false;
		}

		return $this->sendPacket(new AOChatPacket("out", AOCP_PRIVGRP_JOIN, $gid));
	}

	/**
	 * Invite someone to our private group
	 *
	 * @param int|string $user The user to invite to our private group
	 * @return boolean False if the group doesn't exist, otherwise true
	 */
	public function privategroup_invite($user) {
		if (($uid = $this->get_uid($user)) === false) {
			return false;
		}

		return $this->sendPacket(new AOChatPacket("out", AOCP_PRIVGRP_INVITE, $uid));
	}

	/**
	 * Kick someone from this bot's private channel
	 *
	 * @param int|string $user User name or user ID to kick
	 * @return boolean False if the user doesn't exist, otherwise true
	 */
	public function privategroup_kick($user) {
		if (($uid = $this->get_uid($user)) === false) {
			return false;
		}

		return $this->sendPacket(new AOChatPacket("out", AOCP_PRIVGRP_KICK, $uid));
	}

	/**
	 * Leave a private group
	 *
	 * @param int|string $user user id or user name of the private group to leave
	 * @return boolean False if the group doesn't exist, otherwise true
	 */
	public function privategroup_leave($user) {
		if (($uid = $this->get_uid($user)) === false) {
			return false;
		}

		return $this->sendPacket(new AOChatPacket("out", AOCP_PRIVGRP_PART, $uid));
	}

	/**
	 * Kick everyone from this bot's private group
	 *
	 * @return true
	 */
	public function privategroup_kick_all() {
		return $this->sendPacket(new AOChatPacket("out", AOCP_PRIVGRP_KICKALL, ""));
	}

	/**
	 * Add someone to our friend list
	 *
	 * @param int|string $uid The user name or user id to add
	 * @param string $type internal flad, don't use
	 * @return bool false if the user doesn't exist, true otherwise
	 */
	public function buddy_add($uid, $type="\1") {
		if ($uid == $this->char['id']) {
			return false;
		} else {
			return $this->sendPacket(new AOChatPacket("out", AOCP_BUDDY_ADD, array($uid, $type)));
		}
	}

	/**
	 * Remove someone from our friend list
	 *
	 * @param int $uid The user id to remove
	 * @return true
	 */
	public function buddy_remove($uid) {
		return $this->sendPacket(new AOChatPacket("out", AOCP_BUDDY_REMOVE, $uid));
	}

	/**
	 * Remove unknown users from our friend list
	 *
	 * @return true
	 */
	public function buddy_remove_unknown() {
		return $this->sendPacket(new AOChatPacket("out", AOCP_CC, array(array("rembuddy", "?"))));
	}

	/**
	 * Generate a random hex string with $bits bits length
	 *
	 * @param int $bits
	 * @return string A random hexstring in textform
	 */
	public function getRandomHexKey($bits) {
		$str = "";
		do {
			$str .= sprintf('%02x', mt_rand(0, 0xff));
		} while (($bits -= 8) > 0);
		return $str;
	}

	/**
	 * Convert a HEX value into a decimal value
	 *
	 * @param string $x The hex string
	 * @return string The decimal number as a string
	 */
	public function bighexdec($x) {
		if (substr($x, 0, 2) != "0x") {
			return $x;
		}
		$r = "0";
		for ($p = $q = strlen($x) - 1; $p >= 2; $p--) {
			$r = bcadd($r, bcmul(hexdec($x[$p]), bcpow(16, $q - $p)));
		}
		return $r;
	}

	/**
	 * Convert a decimal value to HEX
	 *
	 * @param int|string $x The decimal number as string or int
	 * @return string The HEX representation without leading "0x"
	 */
	public function bigdechex($x) {
		$r = "";
		while ($x != "0") {
			$r = dechex(bcmod($x, 16)) . $r;
			$x = bcdiv($x, 16);
		}
		return $r;
	}

	/**
	 * Raise an arbitrary precision number to another, reduced by a specified modulus
	 *
	 * @param string $base The base as hex string
	 * @param string $exp  The exponent as hex string
	 * @param string $mod  The modulus as hex string
	 * @return string $base^$exp % $mod as hex string
	 */
	public function bcmath_powm($base, $exp, $mod) {
		$base = $this->bighexdec($base);
		$exp  = $this->bighexdec($exp);
		$mod  = $this->bighexdec($mod);

		$r = bcpowmod($base, $exp, $mod);
		return $this->bigdechex($r);
	}

	/**
	 * This function returns the binary equivalent postive integer to a given negative integer of arbitrary length.
	 *
	 * This would be the same as taking a signed negative
	 * number and treating it as if it were unsigned. To see a simple example of this
	 * on Windows, open the Windows Calculator, punch in a negative number, select the
	 * hex display, and then switch back to the decimal display.
	 * @see http://www.hackersquest.com/boards/viewtopic.php?t=4884&start=75
	 *
	 * @param string|int $value The value to convert
	 * @return string The converted value
	 */
	public function negativeToUnsigned($value) {
		if (bccomp($value, 0) != -1) {
			return $value;
		}

		$value = bcmul($value, -1);
		$higherValue = 0xFFFFFFFF;

		// We don't know how many bytes the integer might be, so
		// start with one byte and then grow it byte by byte until
		// our negative number fits inside it. This will make the resulting
		// positive number fit in the same number of bytes.
		while (bccomp($value, $higherValue) == 1) {
			$higherValue = bcadd(bcmul($higherValue, 0x100), 0xFF);
		}

		$value = bcadd(bcsub($higherValue, $value), 1);

		return $value;
	}



	/**
	 * A safe network byte encoder
	 *
	 * On linux systems, unpack("H*", pack("L*", <value>)) returns differently than on Windows.
	 * This can be used instead of unpack/pack to get the value we need.
	 *
	 * @param string|int $value The value to encode
	 * @return string The packed data
	 */
	public function safeDecHexReverseEndian($value) {
		$result = "";
		$value = (int)$this->reduceTo32Bit($value);
		$hex   = substr("00000000".dechex($value), -8);

		$bytes = str_split($hex, 2);

		for ($i = 3; $i >= 0; $i--) {
			$result .= $bytes[$i];
		}

		return $result;
	}

	/**
	 * Takes a number and reduces it to a 32-bit value.
	 *
	 * The 32-bits remain a binary equivalent of 32-bits from the previous number.
	 * If the sign bit is set, the result will be negative, otherwise
	 * the result will be zero or positive.
	 * @author Feetus of RK1
	 *
	 * @param string|int $value The value to reduce
	 * @return int The 32bit value
	 */
	public function reduceTo32Bit($value) {
		// If its negative, lets go positive ... its easier to do everything as positive.
		if (bccomp($value, 0) == -1) {
			$value = $this->negativeToUnsigned($value);
		}

		$bit  = 0x80000000;
		$bits = array();

		// Find the largest bit contained in $value above 32-bits
		while (bccomp($value, $bit) > -1) {
			$bit    = bcmul($bit, 2);
			$bits[] = $bit;
		}

		// Subtract out bits above 32 from $value
		while (null != ($bit = array_pop($bits))) {
			if (bccomp($value, $bit) >= 0) {
				$value = bcsub($value, $bit);
			}
		}

		// Make negative if sign-bit is set in 32-bit value
		if (bccomp($value, 0x80000000) != -1) {
			$value  = bcsub($value, 0x80000000);
			$value -= 0x80000000;
		}

		return $value;
	}

	/**
	 * Generate a Diffie-Hellman login key
	 *
	 * This is 'half' Diffie-Hellman key exchange.
	 * 'Half' as in we already have the server's key ($dhY)
	 * $dhN is a prime and $dhG is generator for it.
	 * @see http://en.wikipedia.org/wiki/Diffie-Hellman_key_exchange
	 *
	 * @param string $servkey The server DH key
	 * @param string $username Our user name
	 * @param string $password Our password
	 * @return string Our login key
	 */
	public function generateLoginKey($servkey, $username, $password) {
		$dhY = "0x9c32cc23d559ca90fc31be72df817d0e124769e809f936bc14360ff4b".
			"ed758f260a0d596584eacbbc2b88bdd410416163e11dbf62173393fbc0c6fe".
			"fb2d855f1a03dec8e9f105bbad91b3437d8eb73fe2f44159597aa4053cf788".
			"d2f9d7012fb8d7c4ce3876f7d6cd5d0c31754f4cd96166708641958de54a6d".
			"ef5657b9f2e92";
		$dhN = "0xeca2e8c85d863dcdc26a429a71a9815ad052f6139669dd659f98ae159".
			"d313d13c6bf2838e10a69b6478b64a24bd054ba8248e8fa778703b41840824".
			"9440b2c1edd28853e240d8a7e49540b76d120d3b1ad2878b1b99490eb4a2a5".
			"e84caa8a91cecbdb1aa7c816e8be343246f80c637abc653b893fd91686cf8d".
			"32d6cfe5f2a6f";
		$dhG = "0x5";
		$dhx = "0x".$this->getRandomHexKey(256);

		$dhX = $this->bcmath_powm($dhG, $dhx, $dhN);
		$dhK = $this->bcmath_powm($dhY, $dhx, $dhN);

		$str = sprintf("%s|%s|%s", $username, $servkey, $password);

		if (strlen($dhK) < 32) {
			$dhK = str_repeat("0", 32-strlen($dhK)) . $dhK;
		} else {
			$dhK = substr($dhK, 0, 32);
		}

		$prefix = pack("H16", $this->getRandomHexKey(64));
		$length = 8 + 4 + strlen($str); /* prefix, int, ... */
		$pad    = str_repeat(" ", (8 - $length % 8) % 8);
		$strlen = pack("N", strlen($str));

		$plain   = $prefix . $strlen . $str . $pad;
		$crypted = $this->aoChatCrypt($dhK, $plain);

		return $dhX . "-" . $crypted;
	}

	/**
	 * Do an AOChat-conform encryption of $str with $key
	 *
	 * @param string $key Key to encrypt with
	 * @param string $str String to encrypt
	 * @return string The encrypted string
	 */
	public function aoChatCrypt($key, $str) {
		if (strlen($key) != 32 || strlen($str) % 8 != 0) {
			return false;
		}

		$ret    = "";

		$keyarr  = unpack("V*", pack("H*", $key));
		$dataarr = unpack("V*", $str);

		$prev = array(0, 0);
		for ($i = 1; $i <= count($dataarr); $i += 2) {
			$now[0] = (int)$this->reduceTo32Bit($dataarr[$i]) ^ (int)$this->reduceTo32Bit(@$prev[0]);
			$now[1] = (int)$this->reduceTo32Bit($dataarr[$i+1]) ^ (int)$this->reduceTo32Bit(@$prev[1]);
			$prev   = $this->aoCryptPermute($now, $keyarr);

			$ret .= $this->safeDecHexReverseEndian($prev[0]);
			$ret .= $this->safeDecHexReverseEndian($prev[1]);
		}

		return $ret;
	}

	/**
	 * Internal encryption function
	 *
	 * @internal
	 * @param int[] $x
	 * @param int[] $y
	 * @return int[]
	 */
	public function aoCryptPermute($x, $y) {
		$a = $x[0];
		$b = $x[1];
		$c = 0;
		$d = (int)0x9e3779b9;
		for ($i = 32; $i-- > 0;) {
			$c  = (int)$this->reduceTo32Bit($c + $d);
			$a += (int)$this->reduceTo32Bit(
				(int)$this->reduceTo32Bit(
					((int)$this->reduceTo32Bit($b) << 4 & -16) + $y[1]
				) ^ (int)$this->reduceTo32Bit($b + $c)
			) ^ (int)$this->reduceTo32Bit(
				((int)$this->reduceTo32Bit($b) >> 5 & 134217727) + $y[2]
			);
			$b += (int)$this->reduceTo32Bit(
				(int)$this->reduceTo32Bit(
					((int)$this->reduceTo32Bit($a) << 4 & -16) + $y[3]
				) ^ (int)$this->reduceTo32Bit($a + $c)
			) ^ (int)$this->reduceTo32Bit(
				((int)$this->reduceTo32Bit($a) >> 5 & 134217727) + $y[4]
			);
		}
		return array($a, $b);
	}
	
	/**
	 * Parse parameters of extended Messages
	 *
	 * @param string $msg The extended message without header
	 * @return mixed[] The extracted parameters
	 */
	public function parseExtParams(&$msg) {
		$args = array();
		while ($msg != '') {
			$data_type = $msg[0];
			$msg = substr($msg, 1); // skip the data type id
			switch ($data_type) {
				case "S":
					$len = ord($msg[0]) * 256 + ord($msg[1]);
					$str = substr($msg, 2, $len);
					$msg = substr($msg, $len + 2);
					$args[] = $str;
					break;

				case "s":
					$len = ord($msg[0]);
					$str = substr($msg, 1, $len - 1);
					$msg = substr($msg, $len);
					$args[] = $str;
					break;

				case "I":
					$array = unpack("N", $msg);
					$args[] = $array[1];
					$msg = substr($msg, 4);
					break;

				case "i":
				case "u":
					$num = $this->b85g($msg);
					$args[] = $num;
					break;

				case "R":
					$cat = $this->b85g($msg);
					$ins = $this->b85g($msg);
					$str = $this->mmdbParser->getMessageString($cat, $ins);
					if ($str === null) {
						$str = "Unknown ($cat, $ins)";
					}
					$args[] = $str;
					break;

				case "l":
					$array = unpack("N", $msg);
					$msg = substr($msg, 4);
					$cat = 20000;
					$ins = $array[1];
					$str = $this->mmdbParser->getMessageString($cat, $ins);
					if ($str === null) {
						$str = "Unknown ($cat, $ins)";
					}
					$args[] = $str;
					break;
					
				case "~":
					// reached end of message
					break 2;

				default:
					$this->logger->log('warn', "Unknown argument type '$data_type'");
					return null;
					break;
			}
		}

		return $args;
	}
	
	/**
	 * Decode the next 5-byte block of 4 ascii85-encoded bytes and move the pointer
	 *
	 * @param string $str The stream to decode, will be modified to point to the next block
	 * @return int The decoded 32bit value
	 */
	public function b85g(&$str) {
		$n = 0;
		for ($i = 0; $i < 5; $i++) {
			$n = $n * 85 + ord($str[$i]) - 33;
		}
		$str = substr($str, 5);
		return $n;
	}
	
	/**
	 * Read an extended message and return it
	 *
	 * New "extended" messages, parser and abstraction.
	 * These were introduced in 16.1.  The messages use postscript
	 * base85 encoding (not ipv6 / rfc 1924 base85).  They also use
	 * some custom encoding and references to further confuse things.
	 *
	 * Messages start with the magic marker ~& and end with ~
	 * Messages begin with two base85 encoded numbers that define
	 * the category and instance of the message.  After that there
	 * are an category/instance defined amount of variables which
	 * are prefixed by the variable type.  A base85 encoded number
	 * takes 5 bytes.  Variable types:
	 *
	 * s: string, first byte is the length of the string
	 * i: signed integer (b85)
	 * u: unsigned integer (b85)
	 * f: float (b85)
	 * R: reference, b85 category and instance
	 * F: recursive encoding
	 * ~: end of message
	 *
	 * @param string $msg The Ascii85 encoded message
	 * @return \Budabot\Core\AOExtMsg|false False if $msg as empty
	 */
	public function readExtMsg($msg) {
		if (empty($msg)) {
			return false;
		}
		
		$message = '';
		while (substr($msg, 0, 2) == "~&") {
			// remove header '~&'
			$msg = substr($msg, 2);

			$obj = new AOExtMsg();
			$obj->category = $this->b85g($msg);
			$obj->instance = $this->b85g($msg);

			$obj->args = $this->parseExtParams($msg);
			if ($obj->args === null) {
				$this->logger->log('warn', "Error parsing parameters for category: '$obj->category' instance: '$obj->instance' string: '$msg'");
			} else {
				$obj->message_string = $this->mmdbParser->getMessageString($obj->category, $obj->instance);
				if ($obj->message_string !== null) {
					$message .= trim(vsprintf($obj->message_string, $obj->args));
				}
			}
		}
		
		return $message;
	}
}
