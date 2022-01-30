<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Monolog\Logger;

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

if ((float)phpversion() < 7.4) {
	die("AOChat class needs PHP version 7.4.0 or higher in order to work.\n");
}

if (!extension_loaded("sockets")) {
	die("AOChat class needs the Sockets extension to work.\n");
}

if (!extension_loaded("bcmath")) {
	die("AOChat class needs the BCMath extension to work.\n");
}

set_time_limit(0);
ini_set("html_errors", "0");

define('AOC_GROUP_NOWRITE',     0x00000002);
define('AOC_GROUP_NOASIAN',     0x00000020);
define('AOC_GROUP_MUTE',        0x01010000);
define('AOC_GROUP_LOG',         0x02020000);

define('AOC_FLOOD_LIMIT',                5);
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
	public const AOC_GROUP_NOWRITE = 0x00000002;
	public const AOC_GROUP_NOASIAN = 0x00000020;
	public const AOC_GROUP_MUTE =    0x01010000;
	public const AOC_GROUP_LOG =     0x02020000;
	public const AOC_FLOOD_LIMIT =            5;
	public const AOC_FLOOD_INC =              2;
	public const AOEM_UNKNOWN =            0xFF;
	public const AOEM_ORG_JOIN =           0x10;
	public const AOEM_ORG_KICK =           0x11;
	public const AOEM_ORG_LEAVE =          0x12;
	public const AOEM_ORG_DISBAND =        0x13;
	public const AOEM_ORG_FORM =           0x14;
	public const AOEM_ORG_VOTE =           0x15;
	public const AOEM_ORG_STRIKE =         0x16;
	public const AOEM_NW_ATTACK =          0x20;
	public const AOEM_NW_ABANDON =         0x21;
	public const AOEM_NW_OPENING =         0x22;
	public const AOEM_NW_TOWER_ATT_ORG =   0x23;
	public const AOEM_NW_TOWER_ATT =       0x24;
	public const AOEM_NW_TOWER =           0x25;
	public const AOEM_AI_CLOAK =           0x30;
	public const AOEM_AI_RADAR =           0x31;
	public const AOEM_AI_ATTACK =          0x32;
	public const AOEM_AI_REMOVE_INIT =     0x33;
	public const AOEM_AI_REMOVE =          0x34;
	public const AOEM_AI_HQ_REMOVE_INIT =  0x35;
	public const AOEM_AI_HQ_REMOVE =       0x36;

	/**
	 * A lookup cache for character name => id and id => character name
	 *
	 * @var array<int|string,int|string> $id
	 */
	public array $id;

	/**
	 * A temporary lookup cache for character name => id and id => character name
	 *
	 * @var array<int|string,int|string> $id
	 */
	public array $tempId = [];

	public array $pendingIdLookups = [];

	/**
	 * A lookup cache for group name => id and id => group name
	 *
	 * @var array<int|string,int|string> $gid
	 */
	public array $gid;

	/**
	 * A cache for character information
	 * @var AOChatChar[] $chars
	 */
	public array $chars;

	/**
	 * The currently logged in character or null if not logged in
	 */
	public AOChatChar $char;

	/**
	 * An associative array where each group's status (muted, etc) is tracked
	 *
	 * Stored as array(
	 * 	group ip => group status
	 * )
	 *
	 * @var array<int,int> $grp
	 */
	public array $grp;

	/**
	 * The socket with which we are connected to the chat server
	 *
	 * @var null|false|\Socket|resource $socket
	 */
	public $socket;

	/**
	 * Timestamp when the last package was received
	 */
	public int $last_packet;

	/**
	 * Timestamp when we sent the last ping
	 */
	public int $last_ping;

	/**
	 * The chat queue
	 */
	public ?QueueInterface $chatqueue;

	/**
	 * The parser for the MMDB
	 */
	public MMDBParser $mmdbParser;

	public LoggerWrapper $logger;

	/** @var int[] */
	public array $buddyQueue = [];

	public function __construct() {
		$this->disconnect();
		$this->mmdbParser = new MMDBParser();
		$this->logger = new LoggerWrapper('Core/AOChat');
	}

	/**
	 * Disconnect from the chat server (if connected) and init variables
	 */
	public function disconnect(): void {
		if (isset($this->socket) && $this->socket !== false) {
			/** @psalm-suppress PossiblyInvalidArgument */
			socket_close($this->socket);
		}
		$this->socket      = null;
		unset($this->char);
		$this->last_packet = 0;
		$this->last_ping   = 0;
		$this->id          = [];
		$this->gid         = [];
		$this->grp         = [];
		$this->chars       = [];
		$this->chatqueue   = null;
	}

	/**
	 * Connect to the chatserver $server on port $port
	 *
	 * @return bool false we cannot connect, otherwise true
	 */
	public function connect(string $server, int $port): bool {
		$this->socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($this->socket === false) {
			$this->socket = null;
			$this->logger->error("Could not create socket: {error}", [
				"error" => trim(socket_strerror(socket_last_error())),
			]);
			return false;
		}

		// prevents bot from hanging on startup when chatserver does not send login seed
		$timeout = 10;
		socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeout, 'usec' => 0]);

		if (@socket_connect($this->socket, $server, $port) === false) {
			$this->logger->error(
				"Could not connect to the AO Chat server ({server}:{port}): {error}",
				[
					"server" => $server,
					"port" => $port,
					"error" => trim(socket_strerror(socket_last_error($this->socket)))
				]
			);

			$this->disconnect();
			return false;
		}

		$this->chatqueue = new LeakyBucket(AOC_FLOOD_LIMIT, AOC_FLOOD_INC);

		return true;
	}

	/**
	 * Send all messages from the chat queue and a ping if necessary
	 */
	public function iteration(): void {
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
	 * @return \Nadybot\Core\AOChatPacket|null The received package or null if none arrived or false if we couldn't parse it
	 */
	public function waitForPacket(int $time=1): ?AOChatPacket {
		$this->iteration();

		$a = [$this->socket];
		$b = [];
		$c = [];
		/** @psalm-suppress InvalidArgument */
		if (!socket_select($a, $b, $c, $time)) {
			return null;
		}
		return $this->getPacket();
	}

	/**
	 * Read $len bytes from the socket
	 */
	public function readData(int $len): string {
		$data = "";
		$rlen = $len;
		while ($rlen > 0) {
			/** @psalm-suppress InvalidArgument */
			if (($tmp = socket_read($this->socket, $rlen)) === false) {
				$lastError = socket_strerror(socket_last_error($this->socket));
				$this->logger->error("Read error: {error}", ["error" => $lastError]);
				die();
			}
			if ($tmp === "") {
				$this->logger->error("Read error: EOF - (Someone else logged in on to same account?)");
				die();
			}
			$data .= $tmp;
			$rlen -= strlen($tmp);
		}
		return $data;
	}

	/**
	 * Read a packet from the socket
	 */
	public function getPacket(): ?AOChatPacket {
		$head = $this->readData(4);
		if (strlen($head) !== 4) {
			return null;
		}

		[, $type, $len] = unpack("n2", $head);

		$data = $this->readData((int)$len);

		$packet = new AOChatPacket("in", (int)$type, $data);

		if ($this->logger->isHandling(Logger::DEBUG)) {
			$refClass = new \ReflectionClass($packet);
			$constants = $refClass->getConstants();
			$codeToConst = array_flip($constants);
			$packName = $codeToConst[$packet->type] ?? null;
			if (isset($packName)) {
				$packName = "$packName ({$packet->type})";
			} else {
				$packName = $packet->type;
			}
			$this->logger->debug(
				"Received package {packName}",
				[
					"packName" => $packName,
					"raw" => join(" ", str_split(bin2hex($head.$data), 2)),
					"data" => $packet->args,
				]
			);
		}

		switch ($type) {
			case AOChatPacket::CLIENT_NAME:
			case AOChatPacket::CLIENT_LOOKUP:
				[$id, $name] = $packet->args;
				$uid = (string)$id;
				$name = ucfirst(strtolower((string)$name));
				$this->id[$uid]  = $name;
				$this->id[$name] = $uid;
				if (isset($this->pendingIdLookups[$name])) {
					foreach ($this->pendingIdLookups[$name]->callbacks as $cb) {
						[$callback, $args] = $cb;
						if ($id === 0xFFFFFFFF) {
							$callback(null, ...$args);
						} else {
							$callback($id, ...$args);
						}
					}
				}
				unset($this->pendingIdLookups[$name]);
				break;

			case AOChatPacket::GROUP_ANNOUNCE:
				[$gid, $name, $status] = $packet->args;
				$this->grp[$gid] = $status;
				$this->gid[$gid] = $name;
				$this->gid[strtolower($name)] = $gid;
				break;

			case AOChatPacket::GROUP_MESSAGE:
				/* Hack to support extended messages */
				if ($packet->args[1] === 0 && substr($packet->args[2], 0, 2) == "~&") {
					$packet->args[2] = $this->readExtMsg($packet->args[2]);
				}
				break;

			case AOChatPacket::CHAT_NOTICE:
				$categoryId = 20000;
				$packet->args[4] = $this->mmdbParser->getMessageString($categoryId, $packet->args[2]);
				if ($packet->args[4] !== null) {
					$packet->args[5] = $this->parseExtParams($packet->args[3]);
					if ($packet->args[5] !== null) {
						$packet->args[6] = vsprintf($packet->args[4], $packet->args[5]);
					} else {
						$this->logger->error("Could not parse chat notice", [
							"packet" => $packet,
						]);
					}
				}
				break;
		}

		$this->last_packet = time();

		return $packet;
	}

	/**
	 * Send a packet
	 */
	public function sendPacket(AOChatPacket $packet): bool {
		$data = pack("n2", $packet->type, strlen($packet->data)) . $packet->data;

		if ($this->logger->isHandling(Logger::DEBUG)) {
			$refClass = new \ReflectionClass($packet);
			$constants = $refClass->getConstants();
			$codeToConst = array_flip($constants);
			$packName = $codeToConst[$packet->type] ?? null;
			if (isset($packName)) {
				$packName = "$packName ({$packet->type})";
			} else {
				$packName = $packet->type;
			}
			$this->logger->debug(
				"Sending package {packName}",
				[
					"packName" => $packName,
					"raw" => join(" ", str_split(bin2hex($data), 2)),
					"data" => ["args" => $packet->args],
				]
			);
		}

		/** @psalm-suppress InvalidArgument */
		socket_write($this->socket, $data, strlen($data));
		return true;
	}

	/**
	 * Login with an account to the server
	 */
	public function authenticate(string $username, string $password): ?array {
		$packet = $this->getPacket();
		if ($packet === null) {
			return null;
		}
		if ($packet->type !== AOChatPacket::LOGIN_SEED) {
			return null;
			$refClass = new \ReflectionClass(AOChatPacket::class);
			$pktLookup = array_flip($refClass->getConstants());
			$pktType  = $pktLookup[$packet->type] ?? "UNKNOWN PACKET";
			$this->logger->error("Wrong answer from login server. Expected {expected}, got {type}", [
				"expected" => "LOGIN_SEED",
				"type" => $pktType
			]);
		}
		$serverseed = $packet->args[0];

		$key = $this->generateLoginKey($serverseed, $username, $password);
		$pak = new AOChatPacket("out", AOChatPacket::LOGIN_REQUEST, [0, $username, $key]);
		$this->sendPacket($pak);
		$packet = $this->getPacket();
		if ($packet === null) {
			return null;
		}
		if ($packet->type === AOChatPacket::LOGIN_ERROR) {
			$this->logger->error("Error from login server: {error}", [
				"error" => $packet->args[0]
			]);
			return null;
		}
		if ($packet->type !== AOChatPacket::LOGIN_CHARLIST) {
			$refClass = new \ReflectionClass(AOChatPacket::class);
			$pktLookup = array_flip($refClass->getConstants());
			$pktType  = $pktLookup[$packet->type] ?? "UNKNOWN PACKET";
			$this->logger->error("Wrong answer from login server. Expected {expected}, got {type}", [
				"expected" => "LOGIN_CHARLIST",
				"type" => $pktType
			]);
			return null;
		}

		for ($i = 0; $i < count($packet->args[0]); $i++) {
			$char = new AOChatChar();
			$char->id = $packet->args[0][$i];
			$char->name = ucfirst(strtolower($packet->args[1][$i]));
			$char->level = $packet->args[2][$i];
			$char->online = $packet->args[3][$i];

			$this->chars []= $char;
		}

		return $this->chars;
	}

	/**
	 * Chose the character to login with
	 *
	 * @param string $char name of the character to login
	 * @return bool true on success, false on error
	 */
	public function login(string $char): bool {
		$char  = ucfirst(strtolower($char));

		foreach ($this->chars as $e) {
			if ($e->name === $char) {
				$char = $e;
				break;
			}
		}

		if (!($char instanceof AOChatChar)) {
			$this->logger->error(
				"The character {charName} is not on this account. Found only {validNames}",
				[
					"validNames" => join(", ", array_column($this->chars, "name")),
					"chars" => $this->chars,
					"charName" => $char,
				]
			);
			return false;
		}

		$loginSelect = new AOChatPacket("out", AOChatPacket::LOGIN_SELECT, $char->id);
		$this->sendPacket($loginSelect);
		$packet = $this->getPacket();
		if ($packet === null) {
			return false;
		}
		if ($packet->type === AOChatPacket::LOGIN_ERROR) {
			$this->logger->error("Error from login server: {error}", [
				"error" => $packet->args[0]
			]);
			return false;
		}
		if ($packet->type !== AOChatPacket::LOGIN_OK) {
			return false;
		}

		$this->char = $char;

		return true;
	}

	/**
	 * Lookup the user id for a username or vice versa
	 * @return string|int|false The user id or false if not found
	 */
	public function lookup_user(null|int|string $u): string|int|false {
		if (is_string($u)) {
			$u = ucfirst(strtolower($u));
		}
		if (!isset($u) || $u === '') {
			return false;
		}

		if (isset($this->id[$u])) {
			return $this->id[$u];
		}

		$this->sendLookupPacket((string)$u);
		for ($i = 0; $i < 100 && !isset($this->id[$u]); $i++) {
			// hack so that packets are not discarding while waiting for char id response
			$packet = $this->waitForPacket(1);
			if ($packet && $this instanceof Nadybot) {
				$this->process_packet($packet);
			}
		}

		return isset($this->id[$u]) ? $this->id[$u] : false;
	}


	/**
	 * @param mixed $args
	 * @psalm-param null|callable(?int,mixed...) $callback
	 */
	public function sendLookupPacket(string $userName, ?callable $callback=null, ...$args): void {
		$time = time();
		$lastLookup = $this->pendingIdLookups[$userName] ?? null;
		if (isset($lastLookup) && $lastLookup->time > $time - 10) {
			return;
		}
		$this->pendingIdLookups[$userName] ??= (object)["callbacks" => []];
		$this->pendingIdLookups[$userName]->time = $time;
		if (isset($callback)) {
			$this->pendingIdLookups[$userName]->callbacks []= [$callback, $args];
		}
		$this->sendPacket(new AOChatPacket("out", AOChatPacket::CLIENT_LOOKUP, $userName));
	}

	/**
	 * Get the user id of a username and handle special cases, such as $user already being a user id.
	 *
	 * @param int|string $user The name of the user to lookup
	 * @return int|false false on error, otherwise the UID
	 */
	public function get_uid(int|string $user): int|false {
		if ($this->isReallyNumeric($user)) {
			return $this->fixunsigned((int)$user);
		}

		$uid = $this->lookup_user((string)$user);

		if ($uid === false || $uid == 0 || $uid == -1 || $uid == 0xffffffff || !$this->isReallyNumeric($uid)) {
			return false;
		}

		return (int)$uid;
	}

	/**
	 * @param mixed $args
	 * @psalm-param callable(?int, mixed...) $callback
	 */
	public function getUid(string $user, callable $callback, ...$args): void {
		if ($this->isReallyNumeric($user)) {
			$callback($this->fixunsigned((int)$user), ...$args);
			return;
		}

		$user = ucfirst(strtolower($user));
		if ($user === '') {
			$callback(null, ...$args);
			return;
		}

		$uid = $this->id[$user] ?? null;
		if (isset($uid)) {
			if ($uid === 0xFFFFFFFF || $uid === "4294967295") {
				$callback(null, ...$args);
			} else {
				$callback((int)$uid, ...$args);
			}
			return;
		}

		$this->sendLookupPacket($user, $callback, ...$args);
	}

	/**
	 * Fix overflows bits for unsigned numbers returned signed
	 */
	public function fixunsigned(int $num): int {
		if (bcdiv((string)$num, "2147483648", 0)) {
			$num2 = bcmul("-1", bcsub("4294967296", (string)$num));
			return (int)$num2;
		}

		return $num;
	}

	/**
	 * Check if $num only consists of digits
	 *
	 * @param mixed $num
	 */
	public function isReallyNumeric($num): bool {
		return is_int($num) || preg_match("/^-?\d+$/", (string)$num);
	}

	/**
	 * Lookup the group id of a group
	 */
	public function lookup_group(int|string $arg, int $type=0): null|int|string {
		if ($type && ($isGid = (strlen((string)$arg) === 5 && (ord(((string)$arg)[0])&~0x80) < 0x10))) {
			return $arg;
		}
		if (!isset($isGid) || !$isGid) {
			$arg = strtolower((string)$arg);
		}
		return $this->gid[$arg] ?? null;
	}

	/**
	 * Get the group id of a group
	 *
	 * @param string $g Name of the group
	 * @return int|string|false Either the group id or false if not found
	 */
	public function get_gid(string $g): int|string|false {
		return $this->lookup_group($g, 1) ?? false;
	}

	/**
	 * Get the group name of a group id
	 *
	 * @param int|string $g The group id
	 * @return int|string|false The group name or false if not found
	 */
	public function get_gname(int|string $g): int|string|false {
		if (($gid = $this->lookup_group($g, 1)) === null) {
			return false;
		}
		return $this->gid[$gid];
	}

	/**
	 * Send a ping packet to keep the connection open
	 */
	public function sendPing(): bool {
		$this->last_ping = time();
		return $this->sendPacket(new AOChatPacket("out", AOChatPacket::PING, "AOChat.php"));
	}

	/**
	 * Send a tell to a user
	 *
	 * @param int|string $user user name or user id
	 */
	public function send_tell($user, string $msg, string $blob="\0", ?int $priority=null): bool {
		if (($uid = $this->get_uid($user)) === false) {
			return false;
		}
		$priority ??= QueueInterface::PRIORITY_MED;
		if (isset($this->chatqueue)) {
			$this->chatqueue->push($priority, new AOChatPacket("out", AOChatPacket::MSG_PRIVATE, [$uid, $msg, $blob]));
		}
		$this->iteration();
		return true;
	}

	/**
	 * Send a message to the guild channel
	 */
	public function send_guild(string $msg, string $blob="\0", int $priority=null): bool {
		$guildGid = false;
		foreach ($this->grp as $gid => $status) {
			if (ord(substr((string)$gid, 0, 1)) == 3) {
				$guildGid = $gid;
				break;
			}
		}
		if (!$guildGid) {
			return false;
		}
		$priority ??= QueueInterface::PRIORITY_MED;
		if (isset($this->chatqueue)) {
			$this->chatqueue->push($priority, new AOChatPacket("out", AOChatPacket::GROUP_MESSAGE, [$guildGid, $msg, "\0"]));
		}
		$this->iteration();
		return true;
	}

	/**
	 * Send a message to a channel
	 *
	 * @param string $group    The channel id or channel name to send to
	 */
	public function send_group(string $group, string $msg, string $blob="\0", int $priority=null): bool {
		if (($gid = $this->get_gid($group)) === false) {
			$this->logger->warning("Trying to send into unknown group \"{$group}\".");
			return false;
		}
		$priority ??= QueueInterface::PRIORITY_MED;
		if (isset($this->chatqueue)) {
			$this->chatqueue->push($priority, new AOChatPacket("out", AOChatPacket::GROUP_MESSAGE, [$gid, $msg, "\0"]));
		}
		$this->iteration();
		return true;
	}

	/**
	 * Join a channel
	 *
	 * @param string $group Channel id or channel name to join
	 */
	public function group_join(string $group): bool {
		if (($gid = $this->get_gid($group)) === false) {
			return false;
		}

		return $this->sendPacket(new AOChatPacket("out", AOChatPacket::GROUP_DATA_SET, [$gid, $this->grp[(int)$gid] & ~AOC_GROUP_MUTE, "\0"]));
	}

	/**
	 * Leave a channel
	 *
	 * @param string $group Channel id or channel name to leave
	 */
	public function group_leave(string $group): bool {
		if (($gid = $this->get_gid($group)) === false) {
			return false;
		}

		return $this->sendPacket(new AOChatPacket("out", AOChatPacket::GROUP_DATA_SET, [$gid, $this->grp[(int)$gid] | AOC_GROUP_MUTE, "\0"]));
	}

	/**
	 * Get a channel's status (log, more, noasian, nowrite)
	 *
	 * @param string $group The group id or group name
	 */
	public function group_status(string $group): ?int {
		if (($gid = $this->get_gid($group)) === false) {
			return null;
		}

		return $this->grp[(int)$gid];
	}

	/**
	 * Send a message to a private group
	 *
	 * @param int|string $group The group id or group name to send to
	 * @param string     $msg   The message to send
	 * @return bool false if the channel doesn't exist, true otherwise
	 */
	public function send_privgroup($group, string $msg): bool {
		if (($gid = $this->get_uid($group)) === false) {
			return false;
		}
		return $this->sendPacket(new AOChatPacket("out", AOChatPacket::PRIVGRP_MESSAGE, [$gid, $msg, "\0"]));
	}

	/**
	 * Join a private group
	 *
	 * @param int|string $group group id or group name to join
	 */
	public function privategroup_join($group): bool {
		if (($gid = $this->get_uid($group)) === false) {
			return false;
		}

		return $this->sendPacket(new AOChatPacket("out", AOChatPacket::PRIVGRP_JOIN, $gid));
	}

	/**
	 * Invite someone to our private group
	 *
	 * @param int|string $user The user to invite to our private group
	 */
	public function privategroup_invite($user): bool {
		if (($uid = $this->get_uid($user)) === false) {
			return false;
		}

		return $this->sendPacket(new AOChatPacket("out", AOChatPacket::PRIVGRP_INVITE, $uid));
	}

	/**
	 * Kick someone from this bot's private channel
	 *
	 * @param int|string $user User name or user ID to kick
	 */
	public function privategroup_kick($user): bool {
		if (($uid = $this->get_uid($user)) === false) {
			return false;
		}

		return $this->sendPacket(new AOChatPacket("out", AOChatPacket::PRIVGRP_KICK, $uid));
	}

	/**
	 * Leave a private group
	 *
	 * @param int|string $user user id or user name of the private group to leave
	 */
	public function privategroup_leave($user): bool {
		if (($uid = $this->get_uid($user)) === false) {
			return false;
		}

		return $this->sendPacket(new AOChatPacket("out", AOChatPacket::PRIVGRP_PART, $uid));
	}

	/**
	 * Kick everyone from this bot's private group
	 */
	public function privategroup_kick_all(): bool {
		return $this->sendPacket(new AOChatPacket("out", AOChatPacket::PRIVGRP_KICKALL, ""));
	}

	/**
	 * Add someone to our friend list
	 */
	public function buddy_add(int $uid, string $payload="\1"): bool {
		if ($uid === $this->char->id??null) {
			return false;
		}
		$this->buddyQueue []= $uid;
		return $this->sendPacket(new AOChatPacket("out", AOChatPacket::BUDDY_ADD, [$uid, $payload]));
	}

	/**
	 * Remove someone from our friend list
	 *
	 * @param int $uid The user id to remove
	 */
	public function buddy_remove($uid): bool {
		return $this->sendPacket(new AOChatPacket("out", AOChatPacket::BUDDY_REMOVE, $uid));
	}

	/**
	 * Remove unknown users from our friend list
	 */
	public function buddy_remove_unknown(): bool {
		return $this->sendPacket(new AOChatPacket("out", AOChatPacket::CC, [["rembuddy", "?"]]));
	}

	/**
	 * Generate a random hex string with $bits bits length
	 */
	public function getRandomHexKey(int $bits): string {
		$str = "";
		do {
			$str .= sprintf('%02x', mt_rand(0, 0xff));
		} while (($bits -= 8) > 0);
		return $str;
	}

	/**
	 * Convert a HEX value into a decimal value
	 */
	public function bighexdec(string $x): string {
		if (substr($x, 0, 2) !== "0x") {
			return $x;
		}
		$r = "0";
		for ($p = $q = strlen($x) - 1; $p >= 2; $p--) {
			$r = bcadd($r, bcmul((string)hexdec($x[$p]), bcpow("16", (string)($q - $p))));
		}
		return $r;
	}

	/**
	 * Convert a decimal value to HEX
	 */
	public function bigdechex(string $x): string {
		if (!is_numeric($x)) {
			throw new Exception("Invalid numeric string encountered: {$x}");
		}
		$r = "";
		while ($x !== "0") {
			$r = dechex((int)bcmod($x, "16")) . $r;
			$x = bcdiv($x, "16");
		}
		return $r;
	}

	/**
	 * Raise an arbitrary precision number to another, reduced by a specified modulus
	 */
	public function bcmath_powm(string $base, string $exp, string $mod): string {
		$base = $this->bighexdec($base);
		$exp  = $this->bighexdec($exp);
		$mod  = $this->bighexdec($mod);
		if (!is_numeric($base) || !is_numeric($exp) || !is_numeric($mod)) {
			throw new Exception("Invalid numeric string encountered: {$base}^{$exp}%{$mod}");
		}

		$r = bcpowmod($base, $exp, $mod);
		return $this->bigdechex($r);
	}

	/**
	 * This function returns the binary equivalent positive integer to a given negative integer of arbitrary length.
	 *
	 * This would be the same as taking a signed negative
	 * number and treating it as if it were unsigned. To see a simple example of this
	 * on Windows, open the Windows Calculator, punch in a negative number, select the
	 * hex display, and then switch back to the decimal display.
	 * @see http://www.hackersquest.com/boards/viewtopic.php?t=4884&start=75
	 */
	public function negativeToUnsigned(float $value): string {
		$strValue = (string)$value;
		if (bccomp($strValue, "0") !== -1) {
			return $strValue;
		}

		$strValue = bcmul($strValue, "-1");
		$higherValue = (string)0xFFFFFFFF;

		// We don't know how many bytes the integer might be, so
		// start with one byte and then grow it byte by byte until
		// our negative number fits inside it. This will make the resulting
		// positive number fit in the same number of bytes.
		while (bccomp($strValue, $higherValue) === 1) {
			$higherValue = bcadd(bcmul($higherValue, (string)0x100), (string)0xFF);
		}

		$strValue = bcadd(bcsub($higherValue, $strValue), "1");

		return $strValue;
	}



	/**
	 * A safe network byte encoder
	 *
	 * On linux systems, unpack("H*", pack("L*", <value>)) returns differently than on Windows.
	 * This can be used instead of unpack/pack to get the value we need.
	 */
	public function safeDecHexReverseEndian(float $value): string {
		$result = "";
		$value = $this->reduceTo32Bit($value);
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
	 * @author Feetus (RK1)
	 */
	public function reduceTo32Bit(float $value): int {
		$strValue = (string)$value;
		// If its negative, lets go positive ... its easier to do everything as positive.
		if (bccomp($strValue, "0") === -1) {
			$strValue = $this->negativeToUnsigned($value);
		}
		if (!is_numeric($strValue)) {
			throw new Exception("Invalid numeric string encountered: {$strValue}");
		}

		$bit32  = (string)0x80000000;
		$bit    = $bit32;
		$bits   = [];

		// Find the largest bit contained in $value above 32-bits
		while (bccomp($strValue, $bit) > -1) {
			$bit    = bcmul($bit, "2");
			$bits[] = $bit;
		}

		// Subtract out bits above 32 from $value
		while (null !== ($bit = array_pop($bits))) {
			if (bccomp($strValue, $bit) >= 0) {
				$strValue = bcsub($strValue, $bit);
			}
		}

		// Make negative if sign-bit is set in 32-bit value
		if (bccomp($strValue, $bit32) !== -1) {
			$strValue = bcsub($strValue, $bit32);
			$strValue = bcsub($strValue, $bit32);
		}

		return (int)$strValue;
	}

	/**
	 * Generate a Diffie-Hellman login key
	 *
	 * This is 'half' Diffie-Hellman key exchange.
	 * 'Half' as in we already have the server's key ($dhY)
	 * $dhN is a prime and $dhG is generator for it.
	 * @see http://en.wikipedia.org/wiki/Diffie-Hellman_key_exchange
	 */
	public function generateLoginKey(string $servkey, string $username, string $password): string {
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
		$length = 8 + 4 + strlen($str); // prefix, int, ...
		$pad    = str_repeat(" ", (8 - $length % 8) % 8);
		$strlen = pack("N", strlen($str));

		$plain   = $prefix . $strlen . $str . $pad;
		$encrypted = $this->aoChatCrypt($dhK, $plain);

		return $dhX . "-" . $encrypted;
	}

	/**
	 * Do an AOChat-conform encryption of $str with $key
	 */
	public function aoChatCrypt(string $key, string $str): string {
		if (strlen($key) !== 32 || strlen($str) % 8 !== 0) {
			throw new Exception("Invalid key or string received.");
		}

		$ret    = "";

		$keyarr  = unpack("V*", pack("H*", $key));
		$dataarr = unpack("V*", $str);

		$prev = [0, 0];
		for ($i = 1; $i <= count($dataarr); $i += 2) {
			$now = [
				$this->reduceTo32Bit($dataarr[$i]) ^ $this->reduceTo32Bit($prev[0]),
				$this->reduceTo32Bit($dataarr[$i+1]) ^ $this->reduceTo32Bit($prev[1])
			];
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
	public function aoCryptPermute(array $x, array $y): array {
		$a = $x[0];
		$b = $x[1];
		$c = 0;
		$d = 0x9e3779b9;
		for ($i = 32; $i-- > 0;) {
			$c  = $this->reduceTo32Bit($c + $d);
			$a += $this->reduceTo32Bit(
				$this->reduceTo32Bit(
					($this->reduceTo32Bit($b) << 4 & -16) + $y[1]
				) ^ $this->reduceTo32Bit($b + $c)
			) ^ $this->reduceTo32Bit(
				($this->reduceTo32Bit($b) >> 5 & 134217727) + $y[2]
			);
			$b += $this->reduceTo32Bit(
				$this->reduceTo32Bit(
					($this->reduceTo32Bit($a) << 4 & -16) + $y[3]
				) ^ $this->reduceTo32Bit($a + $c)
			) ^ $this->reduceTo32Bit(
				($this->reduceTo32Bit($a) >> 5 & 134217727) + $y[4]
			);
		}
		return [$a, $b];
	}

	/**
	 * Parse parameters of extended Messages
	 *
	 * @param string $msg The extended message without header
	 * @return mixed[] The extracted parameters
	 */
	public function parseExtParams(string &$msg): ?array {
		$args = [];
		while ($msg !== '') {
			$dataType = $msg[0];
			$msg = substr($msg, 1); // skip the data type id
			switch ($dataType) {
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
					$this->logger->warning("Unknown argument type '$dataType'");
					return null;
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
	public function b85g(string &$str): int {
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
	 */
	public function readExtMsg(string $msg): ?string {
		if (empty($msg)) {
			return null;
		}

		$message = '';
		while (substr($msg, 0, 2) === "~&") {
			// remove header '~&'
			$msg = substr($msg, 2);

			$obj = new AOExtMsg();
			$obj->category = $this->b85g($msg);
			$obj->instance = $this->b85g($msg);

			$args = $this->parseExtParams($msg);
			if ($args === null) {
				$this->logger->warning("Error parsing parameters for category: '$obj->category' instance: '$obj->instance' string: '$msg'");
			} else {
				$obj->args = $args;
				$obj->message_string = $this->mmdbParser->getMessageString($obj->category, $obj->instance);
				if ($obj->message_string !== null) {
					$message .= trim(vsprintf($obj->message_string, $obj->args));
				}
			}
		}

		return $message;
	}
}
