<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use DateInterval;
use DateTime;
use DateTimeZone;
use Exception;
use Nadybot\Core\{
	CmdContext,
	CommandAlias,
	DB,
	Text,
	Util,
};
use Nadybot\Core\ParamClass\PWord;

/**
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'arbiter',
 *		accessLevel = 'all',
 *		description = 'Show current arbiter mission',
 *		help        = 'arbiter.txt'
 *	)
 */
class ArbiterController {
	public const DIO = "dio";
	public const AI = "ai";
	public const BS = "bs";
	public const CYCLE_LENGTH = 3628800;

	public const DB_TABLE = "icc_arbiter";

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public DB $db;

	/** @Setup */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Arbiter");
		$this->commandAlias->register($this->moduleName, "arbiter", "icc");
	}

	/**
	 * Calculate the next (or current) times for an event
	 */
	public function getNextForType(string $type, ?int $time=null): ArbiterEvent {
		$event = new ArbiterEvent();
		$event->shortName = $type;
		$event->longName = $this->getLongName($event->shortName);
		$time ??= time();
		/** @var ?ICCArbiter */
		$entry = $this->db->table(static::DB_TABLE)
			->where("type", $type)
			->asObj(ICCArbiter::class)
			->first();
		if (!isset($entry)) {
			throw new Exception("No arbiter data found for {$type}.");
		}
		$event->start = $entry->start->getTimestamp();
		$event->end = $entry->end->getTimestamp();
		$cycles = intdiv($time - $event->start, static::CYCLE_LENGTH);
		$event->start += $cycles * static::CYCLE_LENGTH;
		$event->end += $cycles * static::CYCLE_LENGTH;
		if ($event->end <= $time) {
			$event->start += static::CYCLE_LENGTH;
			$event->end += static::CYCLE_LENGTH;
		}
		return $event;
	}

	/**
	 * Calculate the next (or current) times for DIO
	 */
	public function getNextDio(?int $time=null): ArbiterEvent {
		return $this->getNextForType(static::DIO, $time);
	}

	/**
	 * Calculate the next (or current) times for PVP Week
	 */
	public function getNextBS(?int $time=null): ArbiterEvent {
		return $this->getNextForType(static::BS, $time);
	}

	/**
	 * Calculate the next (or current) times for AI
	 */
	public function getNextAI(?int $time=null): ArbiterEvent {
		return $this->getNextForType(static::AI, $time);
	}

	/** Gets the display name for an event */
	public function getLongName(string $short): string {
		switch ($short) {
			case static::BS:
				return "PvP week (Battlestation)";
			case static::AI:
				return "Alien week";
			case static::DIO:
				return "DIO week";
		}
		return "unknown";
	}

	/** Get a nice representation of a duration, rounded to minutes */
	public function niceTimeWithoutSecs(int $time): string {
		return $this->util->unixtimeToReadable($time - ($time % 60));
	}

	/**
	 * @HandlesCommand("arbiter")
	 */
	public function arbiterSetCommand(CmdContext $context, string $action="set", ?PWord $setWeek, ?string $ends="ends"): void {
		$validTypes = [static::AI, static::BS, static::DIO];
		if (!isset($setWeek) || !is_int($pos = array_search($setWeek(), $validTypes))) {
			$context->reply(
				"Allowed current arbiter weeks are ".
				$this->text->enumerate(
					...$this->text->arraySprintf(
						"<highlight>%s<end>",
						...$validTypes
					)
				)
			);
			return;
		}
		$this->db->beginTransaction();
		$day = (new DateTime("now", new DateTimeZone("UTC")))->format("N");
		$startsToday = ($day === "7") && !isset($ends);
		$start =  strtotime($startsToday ? "today" : "last sunday");
		$end = strtotime($startsToday ? "monday + 7 days" : "next monday");
		try {
			$this->db->table(static::DB_TABLE)->truncate();
			for ($i = 0; $i < 3; $i++) {
				$arb = new ICCArbiter();
				/** @psalm-suppress InvalidArrayOffset */
				$arb->type = $validTypes[($pos + $i) % 3];
				$arb->start = (new DateTime())->setTimestamp($start);
				$arb->end = (new DateTime())->setTimestamp($end);
				$days = 14 * $i;
				$arb->start->add(new DateInterval("P{$days}D"));
				$arb->end->add(new DateInterval("P{$days}D"));
				$this->db->insert(static::DB_TABLE, $arb);
			}
		} catch (Exception $e) {
			$this->db->rollback();
			$context->reply(
				"Error saving the new dates into the database: ".
				$e->getMessage()
			);
			return;
		}
		$this->db->commit();
		$context->reply(
			"New times saved. It's currently <highlight>".
			strtoupper($setWeek()) . "<end> week."
		);
	}

	/**
	 * @HandlesCommand("arbiter")
	 */
	public function arbiterCommand(CmdContext $context, ?string $timeGiven): void {
		$time = time();
		if (isset($timeGiven)) {
			$time = strtotime($timeGiven);
			if ($time === false) {
				$context->reply("Unable to parse <highlight>{$timeGiven}<end> into a date.");
				return;
			}
		}
		/** @var ArbiterEvent[] */
		$upcomingEvents = [
			$this->getNextBS($time),
			$this->getNextAI($time),
			$this->getNextDIO($time),
		];

		// Sort them by start date, to the next one coming up or currently on is the first
		usort(
			$upcomingEvents,
			function(ArbiterEvent $e1, ArbiterEvent $e2): int {
				return $e1->start <=> $e2->start;
			}
		);
		$blob = "";
		if ($upcomingEvents[0]->isActiveOn($time)) {
			$currentEvent = array_shift($upcomingEvents);
			$currently = "<highlight>{$currentEvent->longName}<end> for ".
				"<highlight>" . $this->niceTimeWithoutSecs($currentEvent->end - $time) . "<end>";
			$blob .= "Currently: {$currently}\n\n";
			if (isset($timeGiven)) {
				$msg = "On " . ((new DateTime("@{$time}"))->format("d-M-Y")).
					", it's <highlight>{$currentEvent->longName}<end>.";
			} else {
				$msg = "It's currently {$currently}.";
			}
			$currentEvent->start += static::CYCLE_LENGTH;
			$currentEvent->end += static::CYCLE_LENGTH;
			array_push($upcomingEvents, $currentEvent);
		} else {
			if (isset($timeGiven)) {
				$msg = "On " . ((new DateTime("@{$time}"))->format("d-M-Y")) . ", the arbiter is not here.";
			} else {
				$msg = "The arbiter is currently not here.";
			}
			$blob .= "Currently: <highlight>-<end>\n\n";
		}
		foreach ($upcomingEvents as $event) {
			$blob .= $this->util->date($event->start) . ": <highlight>{$event->longName}<end>\n";
		}
		$blob .= "\n\n<i>All arbiter weeks last for 8 days (Sunday 00:00 to Sunday 23:59)</i>";
		if ($upcomingEvents[0]->isActiveOn($time)) {
			$msg = $this->text->blobWrap(
				"{$msg} ",
				$this->text->makeBlob("Upcoming arbiter events", $blob)
			);
		} else {
			$msg = $this->text->blobWrap(
				"{$msg} ",
				$this->text->makeBlob("Next arbiter event", $blob, "Upcoming arbiter events"),
				" is " . $upcomingEvents[0]->longName . " in ".
					$this->niceTimeWithoutSecs($upcomingEvents[0]->start - $time) . "."
			);
		}
		$context->reply($msg);
	}

	/**
	 * @NewsTile("arbiter")
	 * @Description("Shows the current ICC arbiter week - if any")
	 * @Example("<header2>Arbiter<end>
	 * <tab>It's currently <highlight>DIO week<end>.")
	 */
	public function arbiterNewsTile(string $sender, callable $callback): void {
		/** @var ArbiterEvent[] */
		$upcomingEvents = [
			$this->getNextBS(),
			$this->getNextAI(),
			$this->getNextDIO(),
		];

		// Sort them by start date, to the next one coming up or currently on is the first
		usort(
			$upcomingEvents,
			function(ArbiterEvent $e1, ArbiterEvent $e2): int {
				return $e1->start <=> $e2->start;
			}
		);
		if (!$upcomingEvents[0]->isActiveOn(time())) {
			return;
		}
		$currentEvent = array_shift($upcomingEvents);
		$msg = "<header2>Arbiter<end>\n".
			"<tab>It's currently <highlight>{$currentEvent->longName}<end>.";
		$callback($msg);
	}

	/**
	 * @NewsTile("arbiter-force")
	 * @Description("Shows the current ICC arbiter week or what the next one will be")
	 * @Example("<header2>Arbiter<end>
	 * <tab>The arbiter is currently not here.
	 * <tab>DIO week starts in <highlight>3 days 17 hrs 4 mins<end>.")
	 */
	public function arbiterNewsForceTile(string $sender, callable $callback): void {
		/** @var ArbiterEvent[] */
		$upcomingEvents = [
			$this->getNextBS(),
			$this->getNextAI(),
			$this->getNextDIO(),
		];

		// Sort them by start date, to the next one coming up or currently on is the first
		usort(
			$upcomingEvents,
			function(ArbiterEvent $e1, ArbiterEvent $e2): int {
				return $e1->start <=> $e2->start;
			}
		);
		$msg = "<header2>Arbiter<end>\n";
		if (!$upcomingEvents[0]->isActiveOn(time())) {
			$msg .= "<tab>The arbiter is currently not here.\n";
			$nextEvent = array_shift($upcomingEvents);
			$msg .= "<tab><highlight>{$nextEvent->longName}<end> starts in ".
				"<highlight>" . $this->niceTimeWithoutSecs($nextEvent->start - time()) . "<end>.";
		} else {
			$currentEvent = array_shift($upcomingEvents);
			$msg .= "<tab>It's currently <highlight>{$currentEvent->longName}<end>.";
		}
		$callback($msg);
	}
}
