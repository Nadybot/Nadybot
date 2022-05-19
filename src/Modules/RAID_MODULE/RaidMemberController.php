<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	AOChatEvent,
	CmdContext,
	CommandAlias,
	CommandReply,
	DB,
	DBSchema\Player,
	EventManager,
	MessageHub,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Modules\ALTS\AltsController,
	Nadybot,
	ParamClass\PCharacter,
	Routing\RoutableMessage,
	Routing\Source,
	Text,
};
use Nadybot\Modules\ONLINE_MODULE\OnlineController;

#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Member"),
	NCA\DefineCommand(
		command: RaidMemberController::CMD_RAID_JOIN_LEAVE,
		accessLevel: "member",
		description: "Join or leave the raid",
	),
	NCA\DefineCommand(
		command: RaidMemberController::CMD_RAID_KICK_ADD,
		accessLevel: "raid_leader_1",
		description: "Add or remove someone from/to the raid",
	),

	NCA\ProvidesEvent("raid(join)"),
	NCA\ProvidesEvent("raid(leave)"),

	NCA\EmitsMessages("raid", "join"),
	NCA\EmitsMessages("raid", "leave"),
	NCA\EmitsMessages("raid", "kick"),
]
class RaidMemberController extends ModuleInstance {
	public const DB_TABLE = "raid_member_<myname>";
	public const CMD_RAID_JOIN_LEAVE = "raid join/leave";
	public const CMD_RAID_KICK_ADD = "raid kick/add";

	public const ANNOUNCE_OFF = 0;
	public const ANNOUNCE_RAID_FULL = 1;
	public const ANNOUNCE_RAID_OPEN = 2;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public RaidController $raidController;

	#[NCA\Inject]
	public RaidBlockController $raidBlockController;

	#[NCA\Inject]
	public OnlineController $onlineController;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Nadybot $chatBot;

	/** Send a tell to people being added/removed to/from the raid */
	#[NCA\Setting\Boolean]
	public bool $raidInformMemberBeingAdded = true;

	/** Allow people to join the raids on more than one character */
	#[NCA\Setting\Boolean(help: 'multijoin.txt')]
	public bool $raidAllowMultiJoining = true;

	/** Announce that the raid is full when max members is set */
	#[NCA\Setting\Options(
		options: [
			"Off" => self::ANNOUNCE_OFF,
			"When raid is full" => self::ANNOUNCE_RAID_FULL,
			"When raid is full and has space again" => self::ANNOUNCE_RAID_FULL|self::ANNOUNCE_RAID_OPEN,
		],
		accessLevel: 'raid_admin_2',
	)]
	public int $raidAnnounceFull = 0;

	protected function routeMessage(string $type, string $message): int {
		$rMessage = new RoutableMessage($message);
		$rMessage->prependPath(new Source("raid", $type));
		return $this->messageHub->handle($rMessage);
	}

	/**
	 * Resume an old raid after a bot restart
	 */
	public function resumeRaid(Raid $raid): void {
		$this->db->table(self::DB_TABLE)
			->where("raid_id", $raid->raid_id)
			->whereNull("left")
			->update(["left" => time()]);
		$raid->raiders = $this->db->table(self::DB_TABLE)
			->where("raid_id", $raid->raid_id)
			->asObj(RaidMember::class)
			->keyBy("player")->toArray();
	}

	/**
	 * Add player $player to the raid by player $sender
	 */
	public function joinRaid(string $sender, string $player, ?string $source, bool $force=false): ?string {
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
		if (!$this->raidAllowMultiJoining) {
			$alts = $this->altsController->getAltInfo($player)->getAllValidated($player);
			foreach ($alts as $alt) {
				if (isset($raid->raiders[$alt])
					&& $raid->raiders[$alt]->left === null) {
					if ($sender !== $player) {
						return "{$player} is already in the raid with {$alt}.";
					}
					return "You are already in the raid with {$alt}.";
				}
			}
		}
		$numRaiders = $raid->numActiveRaiders();
		$raidIsFull = $raid->max_members > 0 && $numRaiders >= $raid->max_members;
		$countMsg = "";
		if ($raid->max_members > 0) {
			$countMsg = " (" . ($numRaiders + 1) . "/{$raid->max_members} slots)";
		}
		if (($raid->locked || $raidIsFull) && $sender === $player && !$force) {
			if ($raid->locked) {
				$msg = "The raid is currently <off>locked<end>.";
			} else {
				$msg = "The raid is currently <off>full<end> ".
					"with {$numRaiders}/{$raid->max_members} players.";
			}
			if (isset($source) && strncmp($source, 'aopriv', 6) === 0) {
				$msg .= " [" . ((array)$this->text->makeBlob(
					"admin",
					$this->text->makeChatcmd("Add {$player} to the raid", "/tell <myname> raid add {$player}"),
					"Admin controls"
				))[0] . "]";
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
		$this->db->table(self::DB_TABLE)
			->insert([
				"raid_id" => $raid->raid_id,
				"player" => $player,
				"joined" => time(),
			]);
		$msg = null;
		if ($force) {
			if ($this->raidInformMemberBeingAdded) {
				$this->chatBot->sendMassTell("You were <on>added<end> to the raid by {$sender}.", $player);
			}
			$routed = $this->routeMessage("join", "<highlight>{$player}<end> was <on>added<end> to the raid by {$sender}{$countMsg}.");
			if ($routed !== MessageHub::EVENT_DELIVERED) {
				$msg = "<highlight>{$player}<end> was <on>added<end> to the raid{$countMsg}.";
			}
		} else {
			$this->routeMessage(
				"join",
				"<highlight>{$player}<end> has <on>joined<end> the raid{$countMsg} :: ".
				((array)$this->text->makeBlob(
					"click to join",
					$this->raidController->getRaidJoinLink(),
					"Raid information"
				))[0]
			);
			$this->chatBot->sendMassTell("You have <highlight>joined<end> the raid.", $player);
		}
		$numRaiders++;
		if ($numRaiders === $raid->max_members && ($this->raidAnnounceFull & self::ANNOUNCE_RAID_FULL)) {
			$fullMsg = "The raid is now <off>full<end> with {$numRaiders}/{$raid->max_members} members.";
			$routed = $this->routeMessage("join", $fullMsg);
			if ($routed !== MessageHub::EVENT_DELIVERED) {
				if (isset($msg)) {
					return "{$msg}\n{$fullMsg}";
				}
				return $fullMsg;
			}
		}
		return $msg;
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
		$numRaiders = $raid->numActiveRaiders();
		$countMsg = "";
		if ($raid->max_members > 0) {
			$countMsg = " (" . ($numRaiders - 1) . "/{$raid->max_members} slots)";
		}
		$raid->raiders[$player]->left = time();
		$this->db->table(self::DB_TABLE)
			->where("raid_id", $raid->raid_id)
			->where("player", $player)
			->whereNull("left")
			->update(["left" => $raid->raiders[$player]->left]);
		$msg = null;
		if ($sender !== $player) {
			if ($this->raidInformMemberBeingAdded && isset($sender)) {
				$this->chatBot->sendMassTell("You were <off>removed<end> from the raid by {$sender}.", $player);
			}
			$leaveType = (isset($sender) && ($sender !== $player)) ? "kick" : "leave";
			$routed = $this->routeMessage($leaveType, "<highlight>{$player}<end> was <off>removed<end> from the raid{$countMsg}.");
			if ($routed !== MessageHub::EVENT_DELIVERED) {
				$msg = "<highlight>{$player}<end> was <off>removed<end> to the raid{$countMsg}.";
			}
		} else {
			$this->routeMessage("leave", "<highlight>{$player}<end> has <off>left<end> the raid{$countMsg}.");
		}
		if ($numRaiders === $raid->max_members && ($this->raidAnnounceFull & self::ANNOUNCE_RAID_OPEN)) {
			$openMsg = "The raid is <on>no longer full<end>!";
			$routed = $this->routeMessage("leave", $openMsg);
			if ($routed !== MessageHub::EVENT_DELIVERED) {
				if (isset($msg)) {
					return "{$msg}\n{$openMsg}";
				}
				return $openMsg;
			}
		}
		return $msg;
	}

	/**
	 * Join the currently running raid
	 */
	#[NCA\HandlesCommand(self::CMD_RAID_JOIN_LEAVE)]
	#[NCA\Help\Group("raid-members")]
	public function raidJoinCommand(
		CmdContext $context,
		#[NCA\Str("join")] string $action
	): void {
		$reply = $this->joinRaid($context->char->name, $context->char->name, $context->source, false);
		if ($reply !== null) {
			if ($context->isDM()) {
				$this->chatBot->sendMassTell($reply, $context->char->name);
			} else {
				$context->reply($reply);
			}
		}
	}

	/**
	 * Leave the currently running raid
	 */
	#[NCA\HandlesCommand(self::CMD_RAID_JOIN_LEAVE)]
	#[NCA\Help\Group("raid-members")]
	public function raidLeaveCommand(
		CmdContext $context,
		#[NCA\Str("leave")] string $action
	): void {
		$reply = $this->leaveRaid($context->char->name, $context->char->name);
		if ($reply !== null) {
			if ($context->isDM()) {
				$this->chatBot->sendMassTell($reply, $context->char->name);
			} else {
				$context->reply($reply);
			}
		}
	}

	/**
	 * Add someone to the raid, even if they currently cannot join, because it is locked
	 */
	#[NCA\HandlesCommand(self::CMD_RAID_KICK_ADD)]
	#[NCA\Help\Group("raid-members")]
	public function raidAddCommand(
		CmdContext $context,
		#[NCA\Str("add")] string $action,
		PCharacter $char
	): void {
		$reply = $this->joinRaid($context->char->name, $char(), $context->source, true);
		if ($reply !== null) {
			$context->reply($reply);
		}
	}

	/**
	 * Kick someone from the raid
	 */
	#[NCA\HandlesCommand(self::CMD_RAID_KICK_ADD)]
	#[NCA\Help\Group("raid-members")]
	public function raidKickCommand(
		CmdContext $context,
		#[NCA\Str("kick", "rem", "del")] string $action,
		PCharacter $char
	): void {
		$reply = $this->leaveRaid($context->char->name, $char());
		if ($reply !== null) {
			$context->reply($reply);
		}
	}

	/**
	 * Warn everyone on the private channel who's not in the raid $raid
	 * @return string[]
	 */
	public function sendNotInRaidWarning(Raid $raid): array {
		/** @var string[] */
		$notInRaid = [];
		$allowMultilog = $this->raidAllowMultiJoining;
		foreach ($this->chatBot->chatlist as $player => $online) {
			$alts = [$player];
			if (!$allowMultilog) {
				$alts = $this->altsController->getAltInfo($player)->getAllValidated($player);
			}
			$inRaid = false;
			foreach ($alts as $alt) {
				if (isset($raid->raiders[$alt]) && $raid->raiders[$alt]->left === null) {
					$inRaid = true;
					break;
				}
			}
			if (!$inRaid) {
				$notInRaid []= $player;
			}
		}
		if (!count($notInRaid)) {
			return [];
		}
		foreach ($notInRaid as $player) {
			$this->chatBot->sendMassTell(
				"::: <red>Attention<end> ::: <highlight>You are not in the running raid!<end> :: ".
				((array)$this->text->makeBlob(
					"click to join",
					$this->raidController->getRaidJoinLink(),
					"Raid information"
				))[0],
				$player
			);
		}
		return $notInRaid;
	}

	/**
	 * kick everyone on the private channel who's not in the raid $raid
	 * @return string[]
	 */
	public function kickNotInRaid(Raid $raid, bool $all): array {
		/** @var string[] */
		$notInRaid = [];
		foreach ($this->chatBot->chatlist as $player => $online) {
			if (isset($raid->raiders[$player])) {
				// Is or was in the running raid. Could still rejoin
				if (!$all || !isset($raid->raiders[$player]->left)) {
					continue;
				}
			}
			$this->chatBot->privategroup_kick($player);
			$notInRaid []= $player;
		}
		return $notInRaid;
	}

	/**
	 * Get the blob for the !raid list command
	 * @return string[]
	 */
	public function getRaidListBlob(Raid $raid, bool $justBlob=false): array {
		ksort($raid->raiders);
		$lines = [];
		$active = 0;
		$inactive = 0;
		foreach ($raid->raiders as $player => $raider) {
			$line  = "<highlight>{$raider->player}<end>";
			if ($raider->pointsIndividual !== 0) {
				$line .= sprintf(
					" [Points: %d / %+d] ",
					$raider->pointsRewarded,
					$raider->pointsIndividual
				);
			} else {
				$line .= " [Points: {$raider->pointsRewarded}] ";
			}
			if (isset($raider->left)) {
				$line .= "<off>left<end>";
				$inactive++;
			} else {
				$line .= "<on>active<end> ".
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
		$this->playerManager->massGetByName(
			function(array $result) use ($sendto): void {
				$this->sendRaidCheckBlobResult($result, $sendto);
			},
			$activeNames
		);
	}

	/**
	 * Send the raid check blob with all active players to $sendto
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
				($this->onlineController->getProfessionId($pInfo->profession??"unknown")??0).">";
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

	#[NCA\Event(
		name: "leavePriv",
		description: "Remove players from the raid when they leave the channel"
	)]
	public function leavePrivateChannelMessageEvent(AOChatEvent $eventObj): void {
		if (!is_string($eventObj->sender)) {
			return;
		}
		$this->leaveRaid(null, $eventObj->sender);
	}
}
