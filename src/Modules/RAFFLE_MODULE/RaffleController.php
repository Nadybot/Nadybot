<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

use Nadybot\Core\{
	AccessManager,
	CommandReply,
	DB,
	DBRow,
	Event,
	EventManager,
	Modules\ALTS\AltsController,
	Nadybot,
	PrivateChannelCommandReply,
	SettingManager,
	Text,
	Util,
};

/**
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'raffle',
 *		accessLevel = 'all',
 *		description = 'Raffle off items to players',
 *		help        = 'raffle.txt'
 *	)
 * @ProvidesEvent("raffle(start)")
 * @ProvidesEvent("raffle(cancel)")
 * @ProvidesEvent("raffle(end)")
 * @ProvidesEvent("raffle(join)")
 * @ProvidesEvent("raffle(leave)")
 */
class RaffleController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public SettingManager $settingManager;
	
	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Util $util;

	public ?Raffle $raffle = null;
	
	/** @Setup */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "raffle_bonus");
		$this->settingManager->add(
			$this->moduleName,
			"defaultraffletime",
			"How long the raffle should go for",
			"edit",
			"time",
			'3m',
			'1m;2m;3m;4m;5m',
			'',
			'mod',
			"raffle.txt"
		);
		$this->settingManager->add(
			$this->moduleName,
			"raffle_announce_frequency",
			"How much time between each raffle announcement",
			"edit",
			"time",
			'30s',
			'10s;20s;30s;45s;1m',
			'',
			'mod',
			"raffle.txt"
		);
		$this->settingManager->add(
			$this->moduleName,
			"raffle_announce_participants",
			"Announce whenever someone joins or leaves the raffle",
			"edit",
			"number",
			'1',
			"true;false",
			"1;0",
			'mod',
			"raffle.txt"
		);
		$this->settingManager->add(
			$this->moduleName,
			"raffle_bonus_per_loss",
			"Bonus to next roll for a lost raffle",
			"edit",
			"options",
			'5',
			"0;1;2;5;10",
			"0;1;2;5;10",
			'mod',
			"raffle.txt"
		);
		$this->settingManager->add(
			$this->moduleName,
			"share_raffle_bonus_on_alts",
			"Share raffle bonus points between alts",
			"edit",
			"options",
			'1',
			"true;false",
			"1;0",
			'mod',
			"raffle.txt"
		);
	}

	/**
	 * @HandlesCommand("raffle")
	 * @Matches("/^raffle start (.+)$/i")
	 */
	public function raffleStartCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$raffleString = $args[1];
		if (isset($this->raffle)) {
			$msg = "There is already a raffle in progress.";
			$sendto->reply($msg);
			return;
		}

		$duration = $this->settingManager->getInt("defaultraffletime");
		$maybeDuration = explode(" ", $raffleString)[0];
		if (($raffleTime = $this->util->parseTime($maybeDuration)) > 0) {
			$duration = $raffleTime;
			$raffleString = preg_replace("/^.+? /", "", $raffleString);
		}
		$this->raffle = new Raffle();
		$this->raffle->fromString($raffleString);
		$this->raffle->raffler = $sender;
		$this->raffle->end = $this->raffle->start + $duration;
		$this->raffle->sendto = $sendto;
		if ($channel === "msg") {
			$this->raffle->sendto = new PrivateChannelCommandReply(
				$this->chatBot,
				$this->chatBot->setting->default_private_channel
			);
		}
		$event = new RaffleEvent();
		$event->raffle = $this->raffle;
		$event->type = "raffle(start)";
		$this->eventManager->fireEvent($event);

		$this->announceRaffleStart();
	}

	/**
	 * @return string[]
	 */
	protected function getJoinLeaveLinks(): array {
		$result = [];
		$items = $this->raffle->toList();
		for ($i = 0; $i < count($items); $i++) {
			$enterLink = $this->text->makeChatcmd("Enter", "/tell <myname> raffle enter " . ($i+1));
			$leaveLink = $this->text->makeChatcmd("Leave", "/tell <myname> raffle leave " . ($i+1));
			$result []= "Item " . ($i + 1) . ": [$enterLink] [$leaveLink] - <highlight>{$items[$i]}<end>";
		}
		return $result;
	}

	protected function getJoinLeaveBlob(): string {
		$bonusPerLoss = $this->settingManager->getInt("raffle_bonus_per_loss");
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
			"<header2>Items for raffle<end>\n".
			"<tab>" . join("\n<tab>", $this->getJoinLeaveLinks()) . "\n";
		return $blob;
	}

	public function announceRaffleStart(): void {
		$endTime = $this->util->unixtimeToReadable($this->raffle->end - $this->raffle->start);

		$msg = "<highlight>{$this->raffle->raffler}<end> has started a raffle:\n".
			"<yellow>------------------------------------------------<end>\n".
			$this->raffle->toString("<tab>") . "\n".
			"<yellow>------------------------------------------------<end>\n".
			"The raffle will end in <highlight>{$endTime}<end>.\n";
		$blob = $this->getJoinLeaveBlob();
		$msg .= $this->text->makeBlob("Enter / Leave the raffle", $blob, "Raffle actions");
		$this->raffle->sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("raffle")
	 * @Matches("/^raffle cancel$/i")
	 */
	public function raffleCancelCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raffle)) {
			$msg = "There is no active raffle.";
			$sendto->reply($msg);
			return;
		}

		if (($this->raffle->raffler !== $sender) && !$this->accessManager->checkAccess($sender, "mod")) {
			$msg = "Only the owner or a moderator may cancel the raffle.";
			$sendto->reply($msg);
			return;
		}
		$msg = "The raffle was cancelled by <highlight>{$sender}<end>.";
		$this->raffle->sendto->reply($msg);
		$event = new RaffleEvent();
		$event->raffle = $this->raffle;
		$event->type = "raffle(cancel)";
		$this->eventManager->fireEvent($event);
		$this->raffle = null;
	}
	
	/**
	 * @HandlesCommand("raffle")
	 * @Matches("/^raffle end$/i")
	 */
	public function raffleEndCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raffle)) {
			$msg = "There is no active raffle.";
			$sendto->reply($msg);
			return;
		}

		if (($this->raffle->raffler !== $sender) && !$this->accessManager->checkAccess($sender, "mod")) {
			$msg = "Only the owner or a moderator may end the raffle.";
			$sendto->reply($msg);
			return;
		}

		$this->endRaffle();
	}
	
	/**
	 * @HandlesCommand("raffle")
	 * @Matches("/^raffle join$/i")
	 * @Matches("/^raffle join (\d+)$/i")
	 * @Matches("/^raffle enter$/i")
	 * @Matches("/^raffle enter (\d+)$/i")
	 */
	public function raffleEnterCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raffle)) {
			$msg = "There is no active raffle.";
			$sendto->reply($msg);
			return;
		}
		$slot = 0;
		if (count($args) === 1) {
			if (count($this->raffle->slots) > 1) {
				$msg = "There is more than 1 item being raffled. Please say which slot to enter.";
				$sendto->reply($msg);
				return;
			}
		} else {
			$slot = (int)$args[1] - 1;
		}
		$isInRaffle = $this->raffle->isInRaffle($sender, $slot);
		if ($isInRaffle === null) {
			$msg = "There is no item being raffled in slot <highligh>" . ($slot + 1) . "<end>.";
			$sendto->reply($msg);
			return;
		}
		if ($isInRaffle) {
			$msg = "You are already in ther raffle for ".
				$this->raffle->slots[$slot]->toString() . ".";
			$sendto->reply($msg);
			return;
		}
		$this->raffle->slots[$slot]->participants []= $sender;
		$event = new RaffleParticipationEvent();
		$event->raffle = $this->raffle;
		$event->type = "raffle(enter)";
		$event->player = $sender;
		$this->eventManager->fireEvent($event);

		if ($this->settingManager->getBool("raffle_announce_participants")) {
			$this->raffle->sendto->reply(
				"<highlight>$sender<end> has entered the raffle for ".
				$this->raffle->slots[$slot]->toString() . "."
			);
			return;
		}
		$this->chatBot->sendTell(
			"You have entered the raffle for ".
			$this->raffle->slots[$slot]->toString() . ".",
			$sender
		);
	}
	
	/**
	 * @HandlesCommand("raffle")
	 * @Matches("/^raffle leave$/i")
	 * @Matches("/^raffle leave (\d+)$/i")
	 */
	public function raffleLeaveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raffle)) {
			$msg = "There is no active raffle.";
			$sendto->reply($msg);
			return;
		}
		$slot = 0;
		if (count($args) === 1) {
			if (!$this->raffle->isInRaffle($sender)) {
				$msg = "You are currently not in the raffle.";
				$sendto->reply($msg);
				return;
			}
			foreach ($this->raffle->slots as &$slot) {
				$slot->removeParticipant($sender);
			}
			$event = new RaffleParticipationEvent();
			$event->raffle = $this->raffle;
			$event->type = "raffle(leave)";
			$event->player = $sender;
			$this->eventManager->fireEvent($event);
			if ($this->settingManager->getBool("raffle_announce_participants")) {
				$this->raffle->sendto->reply(
					"<highlight>$sender<end> has left the raffle."
				);
				return;
			}
			$msg = "You were removed from all raffle slots.";
			$sendto->reply($msg);
			return;
		}
		$slot = (int)$args[1] - 1;
		if (!isset($this->raffle->slots[$slot])) {
			$msg = "There is no item being raffled in slot <highligh>" . ($slot + 1) . "<end>.";
			$sendto->reply($msg);
			return;
		}

		if (!$this->raffle->slots[$slot]->removeParticipant($sender)) {
			$msg = "You were not in the raffle for ".
				$this->raffle->slots[$slot]->toString() . ".";
			$sendto->reply($msg);
			return;
		}

		$event = new RaffleParticipationEvent();
		$event->raffle = $this->raffle;
		$event->type = "raffle(leave)";
		$event->player = $sender;
		$this->eventManager->fireEvent($event);
		if ($this->settingManager->getBool("raffle_announce_participants")) {
			$this->raffle->sendto->reply(
				"<highlight>$sender<end> has left the raffle for ".
				$this->raffle->slots[$slot]->toString() . "."
			);
			return;
		}
		$this->chatBot->sendTell(
			"You have left the raffle for ".
			$this->raffle->slots[$slot]->toString() . ".",
			$sender
		);
	}
	
	/**
	 * @Event("timer(1sec)")
	 * @Description("Announce and/or end raffle")
	 */
	public function checkRaffleEvent(Event $eventObj): void {
		if (!isset($this->raffle)) {
			return;
		}
		if (time() >= $this->raffle->end) {
			$this->endRaffle();
			return;
		}
		$announceInterval = $this->settingManager->getInt('raffle_announce_frequency');
		if (time() - $this->raffle->lastAnnounce >= $announceInterval) {
			$this->announceRaffle();
		}
	}

	protected function announceRaffle(): void {
		if (!isset($this->raffle)) {
			return;
		}
		$this->raffle->lastAnnounce = time();
		$endTime = $this->util->unixtimeToReadable($this->raffle->end - time());

		$numParticipants = count($this->raffle->getParticipantNames());
		$participantsString = "<highlight>{$numParticipants}<end> people are in raffle.";
		if ($numParticipants === 1) {
			$participantsString = "<highlight>1<end> person is in raffle.";
		}

		$msg = "\n".
			"<yellow>------------------------------------------------<end>\n".
			$this->raffle->toString("<tab>") . "\n".
			"<yellow>------------------------------------------------<end>\n".
			"The raffle will end in <highlight>{$endTime}<end>.\n".
			$participantsString . " ";
		$blob = $this->getJoinLeaveBlob();
		$msg .= $this->text->makeBlob("Enter / Leave the raffle", $blob, "Raffle actions");
		$this->raffle->sendto->reply($msg);
	}

	protected function getBonusPoints(string $player): int {
		if ($this->settingManager->getBool('share_raffle_bonus_on_alts')) {
			$player = $this->altsController->getAltInfo($player)->main;
		}
		$data = $this->db->queryRow(
			"SELECT bonus FROM raffle_bonus_<myname> WHERE name=?",
			$player
		);
		return $data ? $data->bonus : 0;
	}

	/**
	 * @param RaffleResultItem[] $result
	 */
	protected function resultIsUnambiguous(array $result): bool {
		$points = array_map(
			function(RaffleResultItem $item) {
				return $item->points;
			},
			$result
		);
		$uniqPoints = array_unique($points);
		return count($points) === count($uniqPoints);
	}

	/**
	 * @return RaffleResultItem[]
	 */
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
			function(RaffleResultItem $a, RaffleResultItem $b): int {
				return $b->points <=> $a->points;
			}
		);
		for ($i = 0; $i < min($slot->amount, count($result)); $i++) {
			$result[$i]->won = true;
		}
		return $result;
	}
	
	public function endRaffle(): void {
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
	 * @return string[]
	 */
	protected function getMainCharacters(string ...$players): array {
		return array_map(
			function(string $player): string {
				return $this->altsController->getAltInfo($player)->main;
			},
			$players
		);
	}

	/**
	 * If enabled, give losers of this raffle a bonus on next raffle
	 * and reset the bonus for all winners
	 */
	public function adjustBonusPoints(Raffle $raffle): void {
		$bonusPerLoss = $this->settingManager->getInt("raffle_bonus_per_loss");
		if ($bonusPerLoss === 0) {
			return;
		}
		$participants = $raffle->getParticipantNames();
		$winners = $raffle->getWinnerNames();
		$losers = array_values(array_diff($participants, $winners));
		if ($this->settingManager->getBool('share_raffle_bonus_on_alts')) {
			$winners = $this->getMainCharacters(...$winners);
			$losers = $this->getMainCharacters(...$losers);
		}
		$inWinners = join(",", array_fill(0, count($winners), '?'));
		$inLosers =  join(",", array_fill(0, count($losers), '?'));
		$losersUpdate = array_map(
			function(DBRow $row): string {
				return $row->name;
			},
			$this->db->query(
				"SELECT name FROM raffle_bonus_<myname> WHERE name IN ($inLosers)",
				...$losers
			)
		);
		$losersInsert = array_diff($losers, $losersUpdate);
		$inLosers =  join(",", array_fill(0, count($losersUpdate), '?'));
		if (count($losersUpdate)) {
			$this->db->exec(
				"UPDATE raffle_bonus_<myname> SET bonus=bonus+? WHERE name IN ($inLosers)",
				$bonusPerLoss,
				...$losersUpdate
			);
		}
		if (count($losersInsert)) {
			$this->db->query(
				"INSERT INTO raffle_bonus_<myname> (name, bonus) VALUES".
				join(",", array_fill(0, count($losersInsert), "(?, $bonusPerLoss)")),
				...$losersInsert
			);
		}
		$this->db->exec(
			"UPDATE raffle_bonus_<myname> SET bonus=0 WHERE name IN ($inWinners)",
			...$winners
		);
	}

	public function announceRaffleResults(Raffle $raffle): void {
		$bonusPoints = $this->settingManager->getInt("raffle_bonus_per_loss");
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
				$blob .= "<tab>No one entered for this item.\n\n";
				continue;
			}
			foreach ($slot->result as $player) {
				$blob .= "<tab>- ".
					($player->won ? "<green>" : "<red>").
					// ($player->won ? "[<green>:-)<end>]" : "[<red>:-(<end>]").
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
		$blobMsg = "[" . $this->text->makeBlob("details", $blob, "Raffle result details") . "]";
		if ($raffle->slots[0]->amount === 1) {
			$winner = $raffle->slots[0]->getWinnerNames()[0];
			$msg = "The winner of <highlight>" . $raffle->slots[0]->toString() . "<end>".
				" is: <highlight>{$winner}<end>. $blobMsg";
		} else {
			$msg = "The winners of <highlight>" . $raffle->slots[0]->toString() . "<end> are $blobMsg:\n".
				"<tab>- <highlight>".
				join("<end>\n<tab>- <highlight>", $raffle->slots[0]->getWinnerNames()).
				"<end>";
		}
		$raffle->sendto->reply($msg);
	}
}
