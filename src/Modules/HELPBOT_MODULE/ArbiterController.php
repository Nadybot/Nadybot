<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use DateTime;
use Nadybot\Core\{
    CommandAlias,
    CommandReply,
	Text,
	Util,
};

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

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	/** @var array<string,int[]> */
	protected static array $dates = [
		self::AI  => [1606608000, 1607299200],
		self::BS  => [1607817600, 1608508800],
		self::DIO => [1609027200, 1609718400],
	];

	/** @Setup */
	public function setup(): void {
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
		$event->start = static::$dates[$type][0];
		$event->end = static::$dates[$type][1];
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
	 * @Matches("/^arbiter$/i")
	 * @Matches("/^arbiter (.+)$/i")
	 */
	public function arbiterCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$time = time();
		if (count($args) > 1) {
			$time = strtotime($args[1]);
			if ($time === false) {
				$sendto->reply("Unable to parse <highlight>{$args[1]}<end> into a date.");
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
			if (count($args) > 1) {
				$msg = "On " . ((new DateTime("@{$time}"))->format("d-M-Y")).
					", it's <highlight>{$currentEvent->longName}<end>.";
			} else {
				$msg = "It's currently {$currently}.";
			}
			$currentEvent->start += static::CYCLE_LENGTH;
			$currentEvent->end += static::CYCLE_LENGTH;
			array_push($upcomingEvents, $currentEvent);
		} else {
			if (count($args) > 1) {
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
		$msg .= " " . $this->text->makeBlob("Upcoming arbiter events", $blob);
		$sendto->reply($msg);
	}
}
