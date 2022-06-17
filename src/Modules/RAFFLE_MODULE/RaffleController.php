<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	DB,
	Event,
	EventManager,
	ModuleInstance,
	Modules\ALTS\AltsController,
	Nadybot,
	ParamClass\PDuration,
	PrivateChannelCommandReply,
	QueueInterface,
	Text,
	Util,
};
use Nadybot\Modules\RAID_MODULE\RaidController;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "raffle",
		accessLevel: "guest",
		description: "Join or leave raffles",
	),
	NCA\DefineCommand(
		command: RaffleController::CMD_RAFFLE_MANAGE,
		accessLevel: "guest",
		description: "Raffle off items to players",
	),

	NCA\ProvidesEvent("raffle(start)"),
	NCA\ProvidesEvent("raffle(cancel)"),
	NCA\ProvidesEvent("raffle(end)"),
	NCA\ProvidesEvent("raffle(join)"),
	NCA\ProvidesEvent("raffle(leave)")
]
class RaffleController extends ModuleInstance {
	public const DB_TABLE = "raffle_bonus_<myname>";
	public const NO_RAFFLE_ERROR = "There is no active raffle.";

	public const CMD_RAFFLE_MANAGE = "raffle manage";

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public RaidController $raidController;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Util $util;

	/** Should raffles end automatically after some time? */
	#[NCA\Setting\Boolean]
	public bool $raffleEndsAutomatically = true;

	/** Time after which a raffle ends automatically (if enabled) */
	#[NCA\Setting\Time(options: ["1m", "2m", "3m", "4m", "5m"])]
	public int $defaultraffletime = 3 * 60;

	/** How much time between each raffle announcement */
	#[NCA\Setting\Time(options: [
		"10s", "20s", "30s", "45s", "1m", "2m", "3m", "4m", "5m", "10m",
	])]
	public int $raffleAnnounceFrequency = 30;

	/** Announce whenever someone joins or leaves the raffle */
	#[NCA\Setting\Boolean]
	public bool $raffleAnnounceParticipants = true;

	/** Bonus to next roll for a lost raffle */
	#[NCA\Setting\Options(options: [
		'0' => 0,
		'1' => 1,
		'2' => 2,
		'5' => 5,
		'10' => 10,
	])]
	public int $raffleBonusPerLoss = 0;

	/** Share raffle bonus points between alts */
	#[NCA\Setting\Boolean]
	public bool $shareRaffleBonusOnAlts = true;

	/** If a raid is running, only raiders may join the raffle */
	#[NCA\Setting\Boolean]
	public bool $raffleAllowOnlyRaiders = false;

	/** Rank required to cancel other people's raffle */
	#[NCA\Setting\Rank]
	public string $raffleCancelotherRank = "mod";

	public ?Raffle $raffle = null;

	public function getRaffleAdminPage(string $sender): string {
		$blob = "<header2>Join / Leave<end>\n".
			"<tab>" . $this->text->makeChatcmd("Join the raffle", "/tell <myname> raffle join") . "\n".
			"<tab>" . $this->text->makeChatcmd("Leave the raffle", "/tell <myname> raffle leave") . "\n\n".
			"<header2>Announce<end>\n".
			"<tab>" . $this->text->makeChatcmd("Announce raffle", "/tell <myname> raffle announce") . "\n".
			"<tab>" . $this->text->makeChatcmd("Announce raffle closing soon", "/tell <myname> raffle announce {$sender}'s raffle will be closing soon") . "\n".
			"<tab>" . $this->text->makeChatcmd("Announce raffle still open", "/tell <myname> raffle announce {$sender}'s raffle is still running") . "\n\n".
			"<header2>End the raffle<end>\n".
			"<tab>" . $this->text->makeChatcmd("Cancel the raffle", "/tell <myname> raffle cancel") . "\n".
			"<tab>" . $this->text->makeChatcmd("Show winners", "/tell <myname> raffle end") . "\n".
			"<tab>Set a timer:";
		foreach (["20s", "30s", "40s", "1m", "2m"] as $time) {
			$blob .= " [" . $this->text->makeChatcmd($time, "/tell <myname> raffle timer {$time}") . "]";
		}
		$blob .= "\n";

		return $blob;
	}

	/**
	 * Start a raffle for one or more items
	 *
	 * Use 'item1', 'item2' to raffle items separately
	 * Use 'item1'+'item2'[+'item3']... to raffle multiple items as a group
	 * Use &lt;number&gt;x 'item/group' to raffle more than one of an item or group
	 * Use 0x 'item/group' to raffle an unlimited number of an item or group
	 * Use &lt;duration&gt; 'items/groups' to start with a custom timer
	 */
	#[NCA\HandlesCommand(self::CMD_RAFFLE_MANAGE)]
	#[NCA\Help\Example("<symbol>raffle start Alpha Box")]
	#[NCA\Help\Example("<symbol>raffle start Alpha Box, Beta Box")]
	#[NCA\Help\Example("<symbol>raffle start 3x Alpha Box, 3x Beta Box")]
	#[NCA\Help\Example("<symbol>raffle start 3x Alpha Box+Beta Box")]
	#[NCA\Help\Example("<symbol>raffle start 0x Loot order")]
	#[NCA\Help\Example("<symbol>raffle start 30s ACDC")]
	public function raffleStartCommand(
		CmdContext $context,
		#[NCA\Str("start")] string $action,
		string $raffleString
	): void {
		if (isset($this->raffle)) {
			$msg = "There is already a raffle in progress.";
			$context->reply($msg);
			return;
		}

		$duration = null;
		if ($this->raffleEndsAutomatically) {
			$duration = $this->defaultraffletime;
		}
		$maybeDuration = explode(" ", $raffleString)[0];
		if (($raffleTime = $this->util->parseTime($maybeDuration)) > 0) {
			$duration = $raffleTime;
			$raffleString = preg_replace("/^.+? /", "", $raffleString);
		}
		$this->raffle = new Raffle();
		$this->raffle->fromString($raffleString);
		$this->raffle->raffler = $context->char->name;
		$this->raffle->end = isset($duration) ? $this->raffle->start + $duration : null;
		$this->raffle->sendto = $context;
		$this->raffle->announceInterval = $this->raffleAnnounceFrequency;
		if ($context->isDM()) {
			$this->raffle->sendto = new PrivateChannelCommandReply(
				$this->chatBot,
				$this->chatBot->char->name
			);
		}
		$event = new RaffleEvent();
		$event->raffle = $this->raffle;
		$event->type = "raffle(start)";
		$this->eventManager->fireEvent($event);

		$this->announceRaffleStart();
		$adminMsg = $this->text->blobWrap(
			"You can control the raffle via the ",
			$this->text->makeBlob("Raffle Admin Menu", $this->getRaffleAdminPage($context->char->name)),
			"."
		);
		$this->chatBot->sendTell($adminMsg, $context->char->name, QueueInterface::PRIORITY_HIGH);
	}

	public function announceRaffleStart(): void {
		if (!isset($this->raffle)) {
			return;
		}
		$msg = "\n<yellow>:::<end> <red>{$this->raffle->raffler} has started a raffle<end> <yellow>:::<end>\n".
			$this->fancyFrame($this->raffle->toString("<tab>"));
		$blob = $this->getJoinLeaveBlob();
		if ($this->raffle->end) {
			$endTime = $this->util->unixtimeToReadable($this->raffle->end - $this->raffle->start);
			$msg = $this->text->blobWrap(
				"{$msg}The raffle will end in <highlight>{$endTime}<end> :: [",
				$this->text->makeBlob("Join", $blob, "Raffle actions"),
				"]"
			);
		} else {
			$msg = $this->text->blobWrap(
				$msg,
				$this->text->makeBlob("Join the raffle", $blob, "Raffle actions")
			);
		}
		$this->raffle->sendto->reply($msg);
	}

	/** Cancel the running raffle immediately, no one wins */
	#[NCA\HandlesCommand(self::CMD_RAFFLE_MANAGE)]
	public function raffleCancelCommand(
		CmdContext $context,
		#[NCA\Str("cancel", "stop")] string $action
	): void {
		if (!isset($this->raffle)) {
			$context->reply(static::NO_RAFFLE_ERROR);
			return;
		}

		$cancelMinRank = $this->raffleCancelotherRank;
		if (($this->raffle->raffler !== $context->char->name) && !$this->accessManager->checkAccess($context->char->name, $cancelMinRank)) {
			$requiredRank = $this->accessManager->getDisplayName($cancelMinRank);
			$msg = "Only the owner or a {$requiredRank} may cancel the raffle.";
			$context->reply($msg);
			return;
		}
		$msg = "The raffle was <off>cancelled<end> by <highlight>{$context->char->name}<end>.";
		$this->raffle->sendto->reply($msg);
		$event = new RaffleEvent();
		$event->raffle = $this->raffle;
		$event->type = "raffle(cancel)";
		$this->eventManager->fireEvent($event);
		$this->raffle = null;
	}

	/** End the raffle immediately and post the results */
	#[NCA\HandlesCommand(self::CMD_RAFFLE_MANAGE)]
	public function raffleEndCommand(
		CmdContext $context,
		#[NCA\Str("end")] string $action
	): void {
		if (!isset($this->raffle)) {
			$context->reply(static::NO_RAFFLE_ERROR);
			return;
		}

		if (($this->raffle->raffler !== $context->char->name) && !$this->accessManager->checkAccess($context->char->name, "mod")) {
			$msg = "Only the owner or a moderator may end the raffle.";
			$context->reply($msg);
			return;
		}

		$this->endRaffle();
	}

	/**
	 * Set or modify the raffle timer
	 *
	 * If the timer was unset before, the bot will immediately start it now
	 */
	#[NCA\HandlesCommand(self::CMD_RAFFLE_MANAGE)]
	public function raffleTimerCommand(
		CmdContext $context,
		#[NCA\Str("timer")] string $action,
		PDuration $duration
	): void {
		if (!isset($this->raffle)) {
			$context->reply(static::NO_RAFFLE_ERROR);
			return;
		}

		if (($this->raffle->raffler !== $context->char->name) && !$this->accessManager->checkAccess($context->char->name, "mod")) {
			$msg = "Only the owner or a moderator may set or change the raffle timer.";
			$context->reply($msg);
			return;
		}
		$time = $duration->toSecs();
		if ($time === 0) {
			$context->reply("<highlight>{$duration}<end> is not a valid time interval.");
			return;
		}
		$this->raffle->end = time() + $time;

		$this->raffle->sendto->reply(
			"<highlight>{$context->char->name}<end> has started a raffle timer for ".
			"<highlight>" . $this->util->unixtimeToReadable($time) . "<end>."
		);
		$this->announceRaffle();
	}

	/** Announce the raffle, optionally with an extra message */
	#[NCA\HandlesCommand(self::CMD_RAFFLE_MANAGE)]
	public function raffleAnnounceCommand(
		CmdContext $context,
		#[NCA\Str("announce")] string $action,
		?string $message
	): void {
		if (!isset($this->raffle)) {
			$context->reply(static::NO_RAFFLE_ERROR);
			return;
		}

		if (($this->raffle->raffler !== $context->char->name) && !$this->accessManager->checkAccess($context->char->name, "mod")) {
			$msg = "Only the owner or a moderator may announce the raffle.";
			$context->reply($msg);
			return;
		}
		$this->announceRaffle($message);
	}

	/**
	 * Join the currently running raffle.
	 * If more than 1 item is raffled, a slot must be given
	 */
	#[NCA\HandlesCommand("raffle")]
	public function raffleJoinCommand(
		CmdContext $context,
		#[NCA\Str("join", "enter")] string $action,
		?int $slot
	): void {
		if (!isset($this->raffle)) {
			$context->reply(static::NO_RAFFLE_ERROR);
			return;
		}
		$allowOnlyRaiders = $this->raffleAllowOnlyRaiders;
		$raid = $this->raidController->raid;
		if ($allowOnlyRaiders && isset($raid)) {
			if (!isset($raid->raiders[$context->char->name]) || isset($raid->raiders[$context->char->name]->left)) {
				$msg = "You must be in the raid to join the raffle.";
				$context->reply($msg);
				return;
			}
		}

		if (!isset($slot)) {
			if (count($this->raffle->slots) > 1) {
				$msg = "There is more than 1 item being raffled. Please say which slot to join.";
				$context->reply($msg);
				return;
			}
			$slot = 1;
		}
		$slot--;
		$isInRaffle = $this->raffle->isInRaffle($context->char->name, $slot);
		if ($isInRaffle === null) {
			$msg = "There is no item being raffled in slot <highlight>" . ($slot + 1) . "<end>.";
			$context->reply($msg);
			return;
		}
		if ($isInRaffle) {
			$msg = "You are already in the raffle for ".
				$this->raffle->slots[$slot]->toString() . ".";
			$context->reply($msg);
			return;
		}
		$this->raffle->slots[$slot]->participants []= $context->char->name;
		$event = new RaffleParticipationEvent();
		$event->raffle = $this->raffle;
		$event->type = "raffle(enter)";
		$event->player = $context->char->name;
		$this->eventManager->fireEvent($event);

		if ($this->raffleAnnounceParticipants) {
			$msg = "<highlight>{$context->char->name}<end> <on>joined<end> the raffle";
			if (count($this->raffle->slots) > 1) {
				$msg .= " for " . $this->raffle->slots[$slot]->toString();
			}
			$msg .= ".";
			$this->raffle->sendto->reply($msg);
			return;
		}
		$this->chatBot->sendMassTell(
			"You <on>joined<end> the raffle for <highlight>".
			$this->raffle->slots[$slot]->toString() . "<end>.",
			$context->char->name
		);
	}

	/** Leave the raffle for all or just a single slot */
	#[NCA\HandlesCommand("raffle")]
	public function raffleLeaveCommand(
		CmdContext $context,
		#[NCA\Str("leave")] string $action,
		?int $slot
	): void {
		if (!isset($this->raffle)) {
			$context->reply(static::NO_RAFFLE_ERROR);
			return;
		}
		if (!isset($slot)) {
			$slot = 0;
			if (!$this->raffle->isInRaffle($context->char->name)) {
				$msg = "You are currently not in the raffle.";
				$context->reply($msg);
				return;
			}
			foreach ($this->raffle->slots as &$raffleSlot) {
				$raffleSlot->removeParticipant($context->char->name);
			}
			$event = new RaffleParticipationEvent();
			$event->raffle = $this->raffle;
			$event->type = "raffle(leave)";
			$event->player = $context->char->name;
			$this->eventManager->fireEvent($event);
			if ($this->raffleAnnounceParticipants) {
				$this->raffle->sendto->reply(
					"<highlight>{$context->char->name}<end> left the raffle."
				);
				return;
			}
			$msg = "You were removed from all raffle slots.";
			$context->reply($msg);
			return;
		}
		$slot -= 1;
		if (!isset($this->raffle->slots[$slot])) {
			$msg = "There is no item being raffled in slot <highligh>" . ($slot + 1) . "<end>.";
			$context->reply($msg);
			return;
		}

		if (!$this->raffle->slots[$slot]->removeParticipant($context->char->name)) {
			$msg = "You were not in the raffle for ".
				$this->raffle->slots[$slot]->toString() . ".";
			$context->reply($msg);
			return;
		}

		$event = new RaffleParticipationEvent();
		$event->raffle = $this->raffle;
		$event->type = "raffle(leave)";
		$event->player = $context->char->name;
		$this->eventManager->fireEvent($event);
		if ($this->raffleAnnounceParticipants) {
			$msg = "<highlight>{$context->char->name}<end> <off>left<end> the raffle";
			if (count($this->raffle->slots) > 1) {
				$msg .= " for " . $this->raffle->slots[$slot]->toString();
			}
			$msg .= ".";
			$this->raffle->sendto->reply($msg);
			return;
		}
		$this->chatBot->sendMassTell(
			"You <off>left<end> the raffle for <highlight>".
			$this->raffle->slots[$slot]->toString() . "<end>.",
			$context->char->name
		);
	}

	#[NCA\Event(
		name: "timer(1sec)",
		description: "Announce and/or end raffle"
	)]
	public function checkRaffleEvent(Event $eventObj): void {
		if (!isset($this->raffle)) {
			return;
		}
		if (isset($this->raffle->end) && (time() >= $this->raffle->end)) {
			$this->endRaffle();
			return;
		}
		if (!isset($this->raffle->lastAnnounce) || time() - $this->raffle->lastAnnounce >= $this->raffle->announceInterval) {
			$this->announceRaffle();
		}
	}

	public function endRaffle(): void {
		if (!isset($this->raffle)) {
			return;
		}
		$raffle = $this->raffle;
		$this->raffle = null;
		foreach ($raffle->slots as $slot) {
			$slot->result = $this->getSlotResult($slot);
		}
		$event = new RaffleEvent();
		$event->raffle = $raffle;
		$event->type = "raffle(end)";
		$this->eventManager->fireEvent($event);
		$this->announceRaffleResults($raffle);
		$this->adjustBonusPoints($raffle);
	}

	/**
	 * If enabled, give losers of this raffle a bonus on next raffle
	 * and reset the bonus for all winners
	 */
	public function adjustBonusPoints(Raffle $raffle): void {
		$bonusPerLoss = $this->raffleBonusPerLoss;
		if ($bonusPerLoss === 0) {
			return;
		}
		$participants = $raffle->getParticipantNames();
		$winners = $raffle->getWinnerNames();
		$losers = array_values(array_diff($participants, $winners));
		if ($this->shareRaffleBonusOnAlts) {
			$winners = $this->getMainCharacters(...$winners);
			$losers = $this->getMainCharacters(...$losers);
		}
		$losersUpdate = [];
		if (count($losers)) {
			/** @var string[] */
			$losersUpdate = $this->db->table(self::DB_TABLE)
					->whereIn("name", $losers)
					->select("name")
					->pluckAs("name", "string")->toArray();
		}
		$losersInsert = array_diff($losers, $losersUpdate);
		if (count($losersUpdate)) {
			$this->db->table(self::DB_TABLE)
				->whereIn("name", $losersUpdate)
				->increment("bonus", $bonusPerLoss);
		}
		if (count($losersInsert)) {
			$this->db->table(self::DB_TABLE)
				->insert(
					array_map(function (string $loser) use ($bonusPerLoss): array {
						return ["name" => $loser, "bonus" => $bonusPerLoss];
					}, $losersInsert)
				);
		}
		if (count($winners)) {
			$this->db->table(self::DB_TABLE)
				->whereIn("name", $winners)
				->update(["bonus" => 0]);
		}
	}

	public function announceRaffleResults(Raffle $raffle): void {
		$bonusPoints = $this->raffleBonusPerLoss;
		$showBonus = $bonusPoints > 0;
		$blob = "";
		if ($bonusPoints > 0) {
			$blob .= "This raffle used the bonus points system.\n".
				"Winners got their bonus points set to <highlight>0<end>, ".
				"losers gained <highlight>{$bonusPoints}<end> ".
				"bonus points for all upcoming raffles until they won.\n".
				"The raffled points, including added bonus points, are ".
				"in brackets, followed by the bonus points participants had for this raffle.\n\n";
		}
		$blob .= "These are the raffle results.\n\n";
		foreach ($raffle->slots as $slot) {
			$blob .= "<header2>" . $slot->toString() . "<end>\n";
			if (!count($slot->result)) {
				$blob .= "<tab>No one joined for this item.\n\n";
				continue;
			}
			foreach ($slot->result as $player) {
				$blob .= "<tab>- ".
					($player->won ? "<green>" : "<red>").
					"{$player->player}<end> (".
					$player->points.
					($showBonus ? ":{$player->bonus_points}" : "").
					")\n";
			}
			$blob .= "\n";
		}
		if (!count($raffle->getParticipantNames())) {
			$msg = "No one joined the raffle.";
			$raffle->sendto->reply($msg);
			return;
		}
		if (count($raffle->slots) > 1) {
			$msg = $this->text->makeBlob("Raffle results", $blob);
			$raffle->sendto->reply($msg);
			return;
		}
		$blobMsg = $this->text->makeBlob("Details", $blob, "Raffle result details");
		$winners = $raffle->slots[0]->getWinnerNames();
		if ($raffle->slots[0]->amount === 1 || count($winners) === 1) {
			$winner = $winners[0];
			$msg = $this->text->blobWrap(
				"The winner of <highlight>" . $raffle->slots[0]->toString() . "<end>".
				" is: <highlight>{$winner}<end> :: [",
				$blobMsg,
				"]"
			);
		} else {
			$msg = $this->text->blobWrap(
				"The winners of <highlight>" . $raffle->slots[0]->toString() . "<end> are :: [",
				$blobMsg,
				"]:\n".
				"<tab>- <highlight>".
				join("<end>\n<tab>- <highlight>", $winners).
				"<end>"
			);
		}
		$raffle->sendto->reply($msg);
	}

	protected function fancyFrame(string $text): string {
		return "<yellow>" . str_repeat("-", 70) . "<end>\n".
			trim($text) . "\n".
			"<yellow>" . str_repeat("-", 70) . "<end>\n";
	}

	/** @return string[] */
	protected function getJoinLeaveLinks(): array {
		if (!isset($this->raffle)) {
			return [];
		}
		$result = [];
		$items = $this->raffle->toList();
		for ($i = 0; $i < count($items); $i++) {
			$joinLink = $this->text->makeChatcmd("Join", "/tell <myname> raffle join " . ($i+1));
			$leaveLink = $this->text->makeChatcmd("Leave", "/tell <myname> raffle leave " . ($i+1));
			$result []= ((count($items) > 1) ? "Item " . ($i + 1) . ": " : "") . "[{$joinLink}] [{$leaveLink}] - <highlight>{$items[$i]}<end>";
		}
		return $result;
	}

	protected function getJoinLeaveBlob(): string {
		$bonusPerLoss = $this->raffleBonusPerLoss;
		$blob = "";
		if ($bonusPerLoss > 0) {
			$blob = "This raffle uses the bonus points system.\n".
				"Winners of any items will get their bonus points set ".
				"to <highlight>0<end>.\n".
				"Losers will get their bonus points increased by ".
				"<highlight>{$bonusPerLoss}<end> points for all upcoming ".
				"raffles until they won.\n\n";
		}
		$blob .= "[" . $this->text->makeChatcmd("Leave All", "/tell <myname> raffle leave") . "]".
			" Leave raffle for all items\n\n".
			"<header2>Item(s) for raffle<end>\n".
			"<tab>" . join("\n<tab>", $this->getJoinLeaveLinks()) . "\n";
		return $blob;
	}

	protected function announceRaffle(?string $extra=null): void {
		if (!isset($this->raffle)) {
			return;
		}
		$this->raffle->lastAnnounce = time();

		$numParticipants = count($this->raffle->getParticipantNames());
		$participantsString = "<highlight>{$numParticipants}<end> people are in the raffle";
		if ($numParticipants === 1) {
			$participantsString = "<highlight>1<end> person is in the raffle";
		}

		$msg = "\n";
		if (isset($extra)) {
			$msg .= "<yellow>:::<end> <red>{$extra}<end> <yellow>:::<end>\n";
		}
		$msg .= $this->fancyFrame($this->raffle->toString("<tab>"));
		if (isset($this->raffle->end)) {
			$endTime = $this->util->unixtimeToReadable($this->raffle->end - time());
			$msg .= "The raffle will end in <highlight>{$endTime}<end>. ";
		}
		$msg .= $participantsString . " :: [";
		$blob = $this->getJoinLeaveBlob();
		$msg = $this->text->blobWrap(
			$msg,
			$this->text->makeBlob("Join", $blob, "Raffle actions"),
			"]"
		);
		$this->raffle->sendto->reply($msg);
	}

	protected function getBonusPoints(string $player): int {
		if ($this->shareRaffleBonusOnAlts) {
			$player = $this->altsController->getMainOf($player);
		}
		return $this->db->table(self::DB_TABLE)
			->where("name", $player)
			->select("bonus")
			->pluckAs("bonus", "int")->first() ?? 0;
	}

	/** @param RaffleResultItem[] $result */
	protected function resultIsUnambiguous(array $result): bool {
		$points = array_map(
			function (RaffleResultItem $item) {
				return $item->points;
			},
			$result
		);
		$uniqPoints = array_unique($points);
		return count($points) === count($uniqPoints);
	}

	/** @return RaffleResultItem[] */
	protected function getSlotResult(RaffleSlot $slot): array {
		srand();

		/** @var RaffleResultItem[] */
		$result = [];
		if (!count($slot->participants)) {
			return $result;
		}
		foreach ($slot->participants as $player) {
			$playerResult = new RaffleResultItem($player);
			$playerResult->bonus_points = $this->getBonusPoints($player);
			$result[] = $playerResult;
		}
		$numParticipants = count($slot->participants);
		$iteration = 0;
		while ($iteration < $numParticipants * 250 || !$this->resultIsUnambiguous($result)) {
			$result[array_rand($result)]->decreasePoints();
			$result[array_rand($result)]->increasePoints();
			$iteration++;
		}
		foreach ($result as $data) {
			$data->points += $data->bonus_points;
		}
		usort(
			$result,
			function (RaffleResultItem $a, RaffleResultItem $b): int {
				return $b->points <=> $a->points;
			}
		);
		$numWinners = min($slot->amount ?: count($result), count($result));
		for ($i = 0; $i < $numWinners; $i++) {
			$result[$i]->won = true;
		}
		return $result;
	}

	/** @return string[] */
	protected function getMainCharacters(string ...$players): array {
		return array_map(
			function (string $player): string {
				return $this->altsController->getMainOf($player);
			},
			$players
		);
	}
}
