<?php declare(strict_types=1);

namespace Nadybot\Modules\EVENTS_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	CommandReply,
	DB,
	Event,
	Nadybot,
	SettingManager,
	Text,
	Util,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\PlayerManager,
};

/**
 * @author Legendadv (RK2)
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'events',
 *		accessLevel = 'all',
 *		description = 'View/Join/Leave events',
 *		help        = 'events.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'events add .+',
 *		accessLevel = 'mod',
 *		description = 'Add an event',
 *		help        = 'events.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'events (rem|del) .+',
 *		accessLevel = 'mod',
 *		description = 'Remove an event',
 *		help        = 'events.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'events setdesc .+',
 *		accessLevel = 'mod',
 *		description = 'Change or set the description for an event',
 *		help        = 'events.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'events setdate .+',
 *		accessLevel = 'mod',
 *		description = 'Change or set the date for an event',
 *		help        = 'events.txt'
 *	)
 */
class EventsController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public AltsController $altsController;

	/** @Setup */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");

		$this->settingManager->add(
			$this->moduleName,
			"num_events_shown",
			"Maximum number of events shown",
			"edit",
			"number",
			"5",
			"5;10;15;20"
		);
	}

	/**
	 * @HandlesCommand("events")
	 * @Matches("/^events$/i")
	 */
	public function eventsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getEvents();
		if ($msg === null) {
			$msg = "No events entered yet.";
		}
		$sendto->reply($msg);
	}

	public function getEvent(int $id): ?EventModel {
		return $this->db->table("events")
			->where("id", $id)
			->asObj(EventModel::class)
			->first();
	}

	/**
	 * @HandlesCommand("events")
	 * @Matches("/^events join (\d+)$/i")
	 */
	public function eventsJoinCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = $args[1];
		$row = $this->getEvent((int)$id);
		if ($row === null) {
			$msg = "There is no event with id <highlight>$id<end>.";
			$sendto->reply($msg);
			return;
		}
		if (isset($row->event_date) && time() >= ($row->event_date + (3600 * 3))) {
			$msg = "You cannot join an event once it has already passed!";
			$sendto->reply($msg);
			return;
		}
		// cannot join an event after 3 hours past its starttime
		$attendees = $row->getAttendees();
		if (in_array($sender, $attendees)) {
			$msg = "You are already on the event list.";
			$sendto->reply($msg);
			return;
		}
		$attendees []= $sender;
		$this->db->table("events")
			->where("id", $id)
			->update(["event_attendees" => join(",", $attendees)]);
		$msg = "You have been added to the event.";
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("events")
	 * @Matches("/^events leave (\d+)$/i")
	 */
	public function eventsLeaveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = $args[1];
		$row = $this->getEvent((int)$id);
		if ($row === null) {
			$msg = "There is no event with id <highlight>$id<end>.";
			$sendto->reply($msg);
			return;
		}
		if (isset($row->event_date) && time() >= ($row->event_date + (3600 * 3))) {
			$msg = "You cannot leave an event once it has already passed!";
			$sendto->reply($msg);
			return;
		}
		$attendees = $row->getAttendees();
		if (!in_array($sender, $attendees)) {
			$msg = "You are not on the event list.";
			$sendto->reply($msg);
			return;
		}
		$attendees = array_diff($attendees, [$sender]);
		$this->db->table("events")
			->where("id", $id)
			->update(["event_attendees" => join(",", $attendees)]);
		$msg = "You have been removed from the event.";
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("events")
	 * @Matches("/^events list (\d+)$/i")
	 */
	public function eventsListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = $args[1];
		$row = $this->getEvent((int)$id);
		if ($row === null) {
			$msg = "Could not find event with id <highlight>$id<end>.";
			$sendto->reply($msg);
			return;
		}
		if (empty($row->event_attendees)) {
			$msg = "No one has signed up to attend this event.";
			$sendto->reply($msg);
			return;
		}
		$link = $this->text->makeChatcmd("Join this event", "/tell <myname> events join $id")." / ";
		$link .= $this->text->makeChatcmd("Leave this event", "/tell <myname> events leave $id")."\n\n";

		$link .= "<header2>Currently planning to attend<end>\n";
		$eventlist = explode(",", $row->event_attendees);
		$numAttendees = count($eventlist);
		sort($eventlist);
		foreach ($eventlist as $key => $name) {
			$row = $this->playerManager->findInDb($name, $this->db->getDim());
			$info = '';
			if ($row !== null) {
				$info = ", <white>Lvl {$row->level} {$row->profession}<end>";
			}

			$altInfo = $this->altsController->getAltInfo($name);
			$alt = '';
			if (count($altInfo->getAllValidatedAlts()) > 0) {
				if ($altInfo->main == $name) {
					$alt = " <highlight>::<end> " . $this->text->makeChatcmd("Alts", "/tell <myname> alts $name");
				} else {
					$alt = " <highlight>::<end> " . $this->text->makeChatcmd("Alts of {$altInfo->main}", "/tell <myname> alts $name");
				}
			}

			$link .= "<tab>- {$name}{$info} {$alt}\n";
		}
		$msg = $this->text->makeBlob("Players Attending Event $id ($numAttendees)", $link);

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("events add .+")
	 * @Matches("/^events add (.+)$/i")
	 */
	public function eventsAddCommand($message, $channel, $sender, $sendto, $args) {
		$eventName = $args[1];
		$eventId = $this->db->table("events")
			->insertGetId([
				"time_submitted" => time(),
				"submitter_name" => $sender,
				"event_name" => $eventName,
				"event_date" => null,
			]);
		$msg = "Event: '$eventName' was added [Event ID $eventId].";
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("events (rem|del) .+")
	 * @Matches("/^events (?:rem|del) (\d+)$/i")
	 */
	public function eventsRemoveCommand($message, $channel, $sender, $sendto, $args) {
		$id = $args[1];
		$row = $this->getEvent((int)$id);
		if ($row === null) {
			$msg = "Could not find an event with id $id.";
		} else {
			$this->db->table("events")->where("id", $id)->delete();
			$msg = "Event with id {$id} has been deleted.";
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("events setdesc .+")
	 * @Matches("/^events setdesc (\d+) (.+)$/i")
	 */
	public function eventsSetDescCommand($message, $channel, $sender, $sendto, $args) {
		$id = $args[1];
		$desc = $args[2];
		$row = $this->getEvent((int)$id);
		if ($row === null) {
			$msg = "Could not find an event with id $id.";
		} else {
			$this->db->table("events")
				->where("id", $id)
				->update(["event_desc" => $desc]);
			$msg = "Description for event with id $id has been updated.";
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("events setdate .+")
	 * @Matches("/^events setdate (\d+) (\d{4})-(0?[1-9]|1[012])-(0?[1-9]|[12]\d|3[01]) ([0-1]?\d|[2][0-3]):([0-5]\d)(?::([0-5]\d))?$/i")
	 */
	public function eventsSetDateCommand($message, $channel, $sender, $sendto, $args) {
		$id = (int)$args[1];
		$row = $this->getEvent((int)$id);
		if ($row === null) {
			$msg = "Could not find an event with id $id.";
		} else {
			// yyyy-dd-mm hh:mm:ss
			$eventDate = mktime((int)$args[5], (int)$args[6], 0, (int)$args[3], (int)$args[4], (int)$args[2]);
			$this->db->table("events")
				->where("id", $id)
				->update(["event_date" => $eventDate]);
			$msg = "Date/Time for event with id $id has been updated.";
		}
		$sendto->reply($msg);
	}

	public function getEvents(): ?string {
		/** @var Collection<EventModel> */
		$data = $this->db->table("events")
			->orderByDesc("event_date")
			->limit($this->settingManager->getInt('num_events_shown'))
			->asObj(EventModel::class);
		if ($data->count() === 0) {
			return null;
		}
		$upcoming_title = "<header2>Upcoming Events<end>\n\n";
		$past_title = "<header2>Past Events<end>\n\n";
		$updated = 0;
		foreach ($data as $row) {
			if ($row->event_attendees == '') {
				$attendance = 0;
			} else {
				$attendance = count(explode(",", $row->event_attendees));
			}
			if ($updated < $row->time_submitted) {
				$updated = $row->time_submitted;
			}

			$upcoming_events = "";
			$past_events = "";
			if ( !isset($row->event_date) || $row->event_date > time()) {
				if (!isset($row->event_date)) {
					$upcoming = "Event Date: <highlight>Not yet set<end>\n";
				} else {
					$upcoming = "Event Date: <highlight>" . $this->util->date($row->event_date) . "<end>\n";
				}
				$upcoming .= "Event Name: <highlight>$row->event_name<end>     [Event ID $row->id]\n";
				$upcoming .= "Author: <highlight>$row->submitter_name<end>\n";
				$upcoming .= "Attendance: <highlight>" . $this->text->makeChatcmd("$attendance signed up", "/tell <myname> events list $row->id") . "<end>" .
					" [" . $this->text->makeChatcmd("Join", "/tell <myname> events join $row->id") . "/" .
					$this->text->makeChatcmd("Leave", "/tell <myname> events leave $row->id") . "]\n";
				$upcoming .= "Description: <highlight>{$row->event_desc}<end>\n";
				$upcoming .= "Date Submitted: <highlight>" . $this->util->date($row->time_submitted) . "<end>\n\n";
				$upcoming_events = $upcoming.$upcoming_events;
			} else {
				$past = "Event Date: <highlight>" . $this->util->date($row->event_date) . "<end>\n";
				$past .= "Event Name: <highlight>$row->event_name<end>     [Event ID $row->id]\n";
				$past .= "Author: <highlight>$row->submitter_name<end>\n";
				$past .= "Attendance: <highlight>" . $this->text->makeChatcmd("$attendance signed up", "/tell <myname> events list $row->id") . "<end>\n";
				$past .= "Description: <highlight>" . $row->event_desc . "<end>\n";
				$past .= "Date Submitted: <highlight>" . $this->util->date($row->time_submitted) . "<end>\n\n";
				$past_events .= $past;
			}
		}
		if (!$upcoming_events) {
			$upcoming_events = "<i>More to come.  Check back soon!</i>\n\n";
		}
		if (!$past_events) {
			$link = $upcoming_title.$upcoming_events;
		} else {
			$link = $upcoming_title.$upcoming_events.$past_title.$past_events;
		}

		return $this->text->makeBlob("Events" . " [Last updated " . $this->util->date($updated)."]", $link);
	}

	/**
	 * @Event("logOn")
	 * @Description("Show events to org members logging on")
	 */
	public function logonEvent(Event $eventObj) {
		$sender = $eventObj->sender;

		if ($this->chatBot->isReady() && isset($this->chatBot->guildmembers[$sender])) {
			if ($this->hasRecentEvents()) {
				$this->chatBot->sendMassTell($this->getEvents(), $sender);
			}
		}
	}

	/**
	 * @Event("joinPriv")
	 * @Description("Show events to characters joining the private channel")
	 */
	public function joinPrivEvent(Event $eventObj) {
		$sender = $eventObj->sender;

		if ($this->hasRecentEvents()) {
			$this->chatBot->sendMassTell($this->getEvents(), $sender);
		}
	}

	public function hasRecentEvents(): bool {
		$sevenDays = time() - (86400 * 7);
		return $this->db->table("events")
			->where("event_date", ">", $sevenDays)
			->exists();
	}

	/**
	 * @NewsTile("events")
	 * @Description("Shows upcoming events - if any")
	 * @Example("<header2>Events [<u>see more</u>]<end>
	 * <tab>2021-10-31 <highlight>GSP Halloween Party<end>")
	 */
	public function eventsTile(string $sender, callable $callback): void {
		/** @var Collection<EventModel> */
		$data = $this->db->table("events")
			->whereNull("event_date")
			->orWhere("event_date", ">", time())
			->orderBy("event_date")
			->limit($this->settingManager->getInt('num_events_shown'))
			->asObj(EventModel::class);
		if ($data->count() === 0) {
			$callback(null);
			return;
		}
		$eventsLink = $this->text->makeChatcmd("see more", "/tell <myname> events");
		$blob = "<header2>Events [{$eventsLink}]<end>\n";
		$blob .= $data->map(function (EventModel $event): string {
			return "<tab>" . ($event->event_date
				? $this->util->date($event->event_date)
				: "soon").
				": <highlight>{$event->event_name}<end>";
		})->join("\n");
		$callback($blob);
	}
}
