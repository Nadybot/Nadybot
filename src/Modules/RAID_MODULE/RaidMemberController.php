<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\{
	CommandAlias,
	CommandReply,
	DB,
	DBSchema\Player,
	Event,
	EventManager,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	Text,
};
use Nadybot\Modules\ONLINE_MODULE\OnlineController;

/**
 * @Instance
 * @package Nadybot\Modules\POINT_RAID_MODULE
 *
 * @DefineCommand(
 *     command       = 'raid (join|leave)',
 *     accessLevel   = 'member',
 *     description   = 'Join or leave the raid',
 *     help          = 'raiduser.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'raidmember',
 *     accessLevel   = 'raid_leader_1',
 *     description   = 'Add or remove someone from/to the raid',
 *     help          = 'raidmember.txt'
 * )
 *
 * @ProvidesEvent("raid(join)")
 * @ProvidesEvent("raid(leave)")
 */
class RaidMemberController {
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public RaidController $raidController;

	/** @Inject */
	public RaidBlockController $raidBlockController;

	/** @Inject */
	public OnlineController $onlineController;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Nadybot $chatBot;

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->commandAlias->register($this->moduleName, "raidmember add", "raid add");
		$this->commandAlias->register($this->moduleName, "raidmember rem", "raid kick");
		$this->db->loadSQLFile($this->moduleName, "raid_member");
	}

	/**
	 * Resume an old raid after a bot restart
	 */
	public function resumeRaid(Raid $raid): void {
		$this->db->exec(
			"UPDATE `raid_member_<myname>` ".
			"SET `left`=? ".
			"WHERE `raid_id`=?",
			time(),
			$raid->raid_id
		);
		/** @var RaidMember[] */
		$raiders = $this->db->fetchAll(
			RaidMember::class,
			"SELECT * FROM `raid_member_<myname>` WHERE `raid_id`=?",
			$raid->raid_id
		);
		foreach ($raiders as $raider) {
			$raid->raiders[$raider->player] = $raider;
		}
	}

	/**
	 * Add player $player to the raid by player $sender
	 */
	public function joinRaid(string $sender, string $player, string $channel, bool $force=false): ?string {
		$raid = $this->raidController->raid;
		if ($raid === null) {
			return RaidController::ERR_NO_RAID;
		}
		if (isset($raid->raiders[$player])
			&& $raid->raiders[$player]->left === null) {
			if ($sender !== $player) {
				return "{$player} is already in the raid.";
			}
			return "You are already in the raid.";
		}
		if (!isset($this->chatBot->chatlist[$player])) {
			if ($sender !== $player) {
				return "{$player} is not in the private group.";
			}
			return "You are not in the private group.";
		}
		$isBlocked = $this->raidBlockController->isBlocked($player, RaidBlockController::JOIN_RAIDS);
		if ($isBlocked && $sender === $player && !$force) {
			$msg = "You are currently blocked from joining raids.";
			return $msg;
		}
		if ($raid->locked && $sender === $player && !$force) {
			$msg = "The raid is currently <red>locked<end>.";
			if ($channel === 'priv') {
				$msg .= " [" . $this->text->makeBlob(
					"admin",
					$this->text->makeChatcmd("Add {$player} to the raid", "/tell <myname> raid add {$player}"),
					"Admin controls"
				) . "]";
			}
			return $msg;
		}
		if (!isset($raid->raiders[$player])) {
			$raider = new RaidMember();
			$raider->player = $player;
			$raider->raid_id = $raid->raid_id;
			$raid->raiders[$player] = $raider;
		} else {
			$raid->raiders[$player]->joined = time();
			$raid->raiders[$player]->left = null;
		}
		$this->db->exec(
			"INSERT INTO `raid_member_<myname>` (`raid_id`, `player`, `joined`) ".
			"VALUES (?, ?, ?)",
			$raid->raid_id,
			$player,
			$raider->joined
		);
		if ($force) {
			$this->chatBot->sendPrivate("<highlight>{$player}<end> was <green>added<end> to the raid by {$sender}.");
		} else {
			$this->chatBot->sendPrivate(
				"<highlight>{$player}<end> has <green>joined<end> the raid :: ".
				$this->text->makeBlob(
					"click to join",
					$this->raidController->getRaidJoinLink(),
					"Raid information"
				)
			);
			$this->chatBot->sendMassTell("You have <highlight>joined<end> the raid.", $player);
		}
		return null;
	}

	/**
	 * Remove player $player from the raid by player $sender
	 */
	public function leaveRaid(?string $sender, string $player): ?string {
		$raid = $this->raidController->raid;
		if ($raid === null) {
			return RaidController::ERR_NO_RAID;
		}
		if (!isset($raid->raiders[$player])
			|| $raid->raiders[$player]->left !== null) {
			if ($sender !== $player) {
				return "{$player} is currently not in the raid.";
			}
			return "You are currently not in the raid.";
		}
		$raid->raiders[$player]->left = time();
		$this->db->exec(
			"UPDATE `raid_member_<myname>` SET `left`=? WHERE `raid_id`=? AND `player`=? AND `left` IS NULL",
			$raid->raiders[$player]->left,
			$raid->raid_id,
			$player
		);
		if ($sender !== $player) {
			$this->chatBot->sendPrivate("<highlight>{$player}<end> was <red>removed<end> from the raid.");
		} else {
			$this->chatBot->sendPrivate("<highlight>{$player}<end> <red>left<end> the raid.");
		}
		return null;
	}

	/**
	 * @HandlesCommand("raid (join|leave)")
	 * @Matches("/^raid join$/i")
	 */
	public function raidJoinCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$reply = $this->joinRaid($sender, $sender, $channel, false);
		if ($reply !== null) {
			if ($channel === "msg") {
				$this->chatBot->sendMassTell($reply, $sender);
			} else {
				$sendto->reply($reply);
			}
		}
	}

	/**
	 * @HandlesCommand("raid (join|leave)")
	 * @Matches("/^raid leave$/i")
	 */
	public function raidLeaveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$reply = $this->leaveRaid($sender, $sender);
		if ($reply !== null) {
			if ($channel === "msg") {
				$this->chatBot->sendMassTell($reply, $sender);
			} else {
				$sendto->reply($reply);
			}
		}
	}

	/**
	 * @HandlesCommand("raidmember")
	 * @Matches("/^raidmember add (.+)$/i")
	 */
	public function raidAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$reply = $this->joinRaid($sender, ucfirst(strtolower($args[1])), $channel, true);
		if ($reply !== null) {
			$sendto->reply($reply);
		}
	}

	/**
	 * @HandlesCommand("raidmember")
	 * @Matches("/^raidmember (?:rem|del|kick) (.+)$/i")
	 */
	public function raidKickCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$reply = $this->leaveRaid($sender, ucfirst(strtolower($args[1]));
		if ($reply !== null) {
			$sendto->reply($reply);
		}
	}

	/**
	 * Warn everyone on the private channel who's not in the raid $raid
	 *
	 * @return string[]
	 */
	public function sendNotInRaidWarning(Raid $raid): array {
		/** @var string[] */
		$notInRaid = [];
		foreach ($this->chatBot->chatlist as $player => $online) {
			if (!isset($raid->raiders[$player]) || $raid->raiders[$player]->left !== null) {
				$notInRaid []= $player;
			}
		}
		if (!count($notInRaid)) {
			return [];
		}
		foreach ($notInRaid as $player) {
			$this->chatBot->sendMassTell(
				"::: <red>Attention<end> ::: <highlight>You are not in the running raid!<end> :: ".
				$this->text->makeBlob(
					"click to join",
					$this->raidController->getRaidJoinLink(),
					"Raid information"
				),
				$player
			);
		}
		return $notInRaid;
	}

	/**
	 * Get the blob for the !raid list command
	 */
	public function getRaidListBlob(Raid $raid, bool $justBlob=false): array {
		ksort($raid->raiders);
		$lines = [];
		$active = 0;
		$inactive = 0;
		foreach ($raid->raiders as $player => $raider) {
			$line  = "<highlight>{$raider->player}<end>";
			$line .= " [Points: {$raider->points}] ";
			if (isset($raider->left)) {
				$line .= "<red>left<end>";
				$inactive++;
			} else {
				$line .= "<green>active<end> ".
					"[" . $this->text->makeChatcmd(
						"Kick",
						"/tell <myname> raid kick {$player}"
					) . "]";
				$active++;
				if ($this->raidBlockController->isBlocked($player, RaidBlockController::POINTS_GAIN)) {
					$line .= " :: blocked from ".
						$this->raidBlockController->blockToString(RaidBlockController::POINTS_GAIN);
				}
			}
			$lines []= $line;
		}
		$blob = join("\n", $lines);
		$blobMsgs = (array)$this->text->makeBlob("click to view", $blob, "Raid User List");
		if ($justBlob) {
			return $blobMsgs;
		}
		foreach ($blobMsgs as &$msg) {
			$msg = "<highlight>{$active}<end> active ".
				"and <highlight>{$inactive}<end> inactive ".
				"player" . (($inactive !== 1) ? "s" : "") . " in the raid :: $msg";
		}
		return  $blobMsgs;
	}

	/**
	 * Send the blob for the !raid check command to $sendto
	 */
	public function sendRaidCheckBlob(Raid $raid, CommandReply $sendto): void {
		$activeNames = [];
		foreach ($raid->raiders as $player => $raider) {
			if ($raider->left !== null) {
				continue;
			}
			$activeNames []= $raider->player;
		}
		$this->playerManager->massGetByNameAsync(
			function(array $result) use ($sendto): void {
				$this->sendRaidCheckBlobResult($result, $sendto);
			},
			$activeNames
		);
	}

	/**
	 * Send the raid check blob with all active players to $sendto
	 *
	 * @param array<string,?Player> $activePlayers List of all the players in the raid
	 * @param CommandReply $sendto Where to send the reply to
	 */
	protected function sendRaidCheckBlobResult(array $activePlayers, CommandReply $sendto): void {
		ksort($activePlayers);
		$lines = [];
		foreach ($activePlayers as $name => $pInfo) {
			if ($pInfo === null) {
				continue;
			}
			$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".
				$this->onlineController->getProfessionId($pInfo->profession).">";
			$line  = "<tab>{$profIcon} {$pInfo->name} - ".
				"{$pInfo->level}/{$pInfo->ai_level} ".
				"<" . strtolower($pInfo->faction) . ">{$pInfo->faction}<end>".
				" [".
				$this->text->makeChatcmd("Raid Kick", "/tell <myname> raid kick $name").
				"]";
			$lines []= $line;
		}
		if (count($activePlayers) === 0) {
			$sendto->reply("<highlight>No<end> players in the raid");
			return;
		}
		$checkCmd = $this->text->makeChatcmd(
			"Check all raid members",
			"/assist " . join(" \\n /assist ", array_keys($activePlayers))
		);
		$notInCmd = $this->text->makeChatcmd(
			"raid notin",
			"/tell <myname> raid notin"
		);
		$blob = "Send not-in-raid warning: {$notInCmd}\n".
			"\n".
			"{$checkCmd}\n".
			"\n".
			join("\n", $lines);
		$blobs = (array)$this->text->makeBlob("click to view", $blob, "Players in the raid");
		foreach ($blobs as &$msg) {
			$msg = "<highlight>" . count($activePlayers) . "<end> player".
				((count($activePlayers) !== 1) ? "s" : "") . " in the raid :: $msg";
		}
		$sendto->reply($blobs);
	}

	/**
	 * @Event("leavePriv")
	 * @Description("Remove players from the raid when they leave the channel")
	 */
	public function leavePrivateChannelMessageEvent(Event $eventObj): void {
		$this->leaveRaid(null, $eventObj->sender);
	}
}
