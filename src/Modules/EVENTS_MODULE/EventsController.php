<?php declare(strict_types=1);

namespace Nadybot\Modules\EVENTS_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	AOChatEvent,
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Nadybot,
	Text,
	Util,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	ParamClass\PRemove,
	UserStateEvent,
};

/**
 * @author Legendadv (RK2)
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "events",
		accessLevel: "guest",
		description: "View/Join/Leave events",
		alias: 'event',
	),
	NCA\DefineCommand(
		command: EventsController::CMD_EVENT_MANAGE,
		accessLevel: "mod",
		description: "Add/change or delete an event",
	),
]
class EventsController extends ModuleInstance {
	public const CMD_EVENT_MANAGE = "events add/change/delete";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public AltsController $altsController;

	/** Maximum number of events shown */
	#[NCA\Setting\Number(options: [5, 10, 15, 20])]
	public int $numEventsShown = 5;

	/** Show the five closest past and upcoming events */
	#[NCA\HandlesCommand("events")]
	public function eventsCommand(CmdContext $context): void {
		$msg = $this->getEvents();
		if ($msg === null) {
			$msg = "No events entered yet.";
		}
		$context->reply($msg);
	}

	/**
	 * Add a new event
	 *
	 * An event ID is returned when you submit an event.
	 * This is the ID you will use to change data regarding that event.
	 */
	#[NCA\HandlesCommand(self::CMD_EVENT_MANAGE)]
	public function eventsAddCommand(CmdContext $context, #[NCA\Str("add")] string $action, string $eventName): void {
		$eventId = $this->db->table("events")
			->insertGetId([
				"time_submitted" => time(),
				"submitter_name" => $context->char->name,
				"event_name" => $eventName,
				"event_date" => null,
			]);
		$msg = "Event: '$eventName' was added [Event ID $eventId].";
		$context->reply($msg);
	}

	/** Delete an event */
	#[NCA\HandlesCommand(self::CMD_EVENT_MANAGE)]
	public function eventsRemoveCommand(CmdContext $context, PRemove $action, int $id): void {
		$row = $this->getEvent($id);
		if ($row === null) {
			$msg = "Could not find an event with id $id.";
		} else {
			$this->db->table("events")->where("id", $id)->delete();
			$msg = "Event with id {$id} has been deleted.";
		}
		$context->reply($msg);
	}

	/** Change the description of an event */
	#[NCA\HandlesCommand(self::CMD_EVENT_MANAGE)]
	public function eventsSetDescCommand(CmdContext $context, #[NCA\Str("setdesc")] string $action, int $id, string $description): void {
		$row = $this->getEvent($id);
		if ($row === null) {
			$msg = "Could not find an event with id $id.";
		} else {
			$this->db->table("events")
				->where("id", $id)
				->update(["event_desc" => $description]);
			$msg = "Description for event with id $id has been updated.";
		}
		$context->reply($msg);
	}

	/** Change the date of an event */
	#[NCA\HandlesCommand(self::CMD_EVENT_MANAGE)]
	public function eventsSetDateCommand(
		CmdContext $context,
		#[NCA\Str("setdate")] string $action,
		int $id,
		#[NCA\Regexp("\d{4}-(?:0?[1-9]|1[012])-(?:0?[1-9]|[12]\d|3[01])\s+(?:[0-1]?\d|[2][0-3]):(?:[0-5]\d)(?::([0-5]\d))?")] string $date
	): void {
		$row = $this->getEvent($id);
		if ($row === null) {
			$msg = "Could not find an event with id $id.";
		} else {
			// yyyy-dd-mm hh:mm:ss
			$eventDate = \Safe\strtotime($date);
			$this->db->table("events")
				->where("id", $id)
				->update(["event_date" => $eventDate]);
			$msg = "Date/Time for event with id $id has been updated.";
		}
		$context->reply($msg);
	}

	public function getEvent(int $id): ?EventModel {
		return $this->db->table("events")
			->where("id", $id)
			->asObj(EventModel::class)
			->first();
	}

	/** Join event #id */
	#[NCA\HandlesCommand("events")]
	public function eventsJoinCommand(CmdContext $context, #[NCA\Str("join")] string $action, int $id): void {
		$row = $this->getEvent($id);
		if ($row === null) {
			$msg = "There is no event with id <highlight>$id<end>.";
			$context->reply($msg);
			return;
		}
		if (isset($row->event_date) && time() >= ($row->event_date + (3600 * 3))) {
			$msg = "You cannot join an event once it has already passed!";
			$context->reply($msg);
			return;
		}
		// cannot join an event after 3 hours past its starttime
		$attendees = $row->getAttendees();
		if (in_array($context->char->name, $attendees)) {
			$msg = "You are already on the event list.";
			$context->reply($msg);
			return;
		}
		$attendees []= $context->char->name;
		$this->db->table("events")
			->where("id", $id)
			->update(["event_attendees" => join(",", $attendees)]);
		$msg = "You have been added to the event.";
		$context->reply($msg);
	}

	/** Leave event #id */
	#[NCA\HandlesCommand("events")]
	public function eventsLeaveCommand(CmdContext $context, #[NCA\Str("leave")] string $action, int $id): void {
		$row = $this->getEvent($id);
		if ($row === null) {
			$msg = "There is no event with id <highlight>{$id}<end>.";
			$context->reply($msg);
			return;
		}
		if (isset($row->event_date) && time() >= ($row->event_date + (3600 * 3))) {
			$msg = "You cannot leave an event once it has already passed!";
			$context->reply($msg);
			return;
		}
		$attendees = $row->getAttendees();
		if (!in_array($context->char->name, $attendees)) {
			$msg = "You are not on the event list.";
			$context->reply($msg);
			return;
		}
		$attendees = array_diff($attendees, [$context->char->name]);
		$this->db->table("events")
			->where("id", $id)
			->update(["event_attendees" => join(",", $attendees)]);
		$msg = "You have been removed from the event.";
		$context->reply($msg);
	}

	/** List all characters marked as joining event #id */
	#[NCA\HandlesCommand("events")]
	public function eventsListCommand(CmdContext $context, #[NCA\Str("list")] string $action, int $id): void {
		$row = $this->getEvent($id);
		if ($row === null) {
			$msg = "Could not find event with id <highlight>$id<end>.";
			$context->reply($msg);
			return;
		}
		if (empty($row->event_attendees)) {
			$msg = "No one has signed up to attend this event.";
			$context->reply($msg);
			return;
		}
		$link = "[" . $this->text->makeChatcmd("join this event", "/tell <myname> events join $id")."] ";
		$link .= "[" . $this->text->makeChatcmd("leave this event", "/tell <myname> events leave $id")."]\n\n";

		$link .= "<header2>Currently planning to attend<end>\n";
		$eventlist = explode(",", $row->event_attendees);
		$numAttendees = count($eventlist);
		sort($eventlist);
		foreach ($eventlist as $key => $name) {
			$row = $this->playerManager->findInDb($name, $this->db->getDim());
			$info = '';
			if ($row !== null) {
				$info = ", <highlight>Lvl {$row->level} {$row->profession}<end>";
			}

			$altInfo = $this->altsController->getAltInfo($name);
			$alt = '';
			if (count($altInfo->getAllValidatedAlts()) > 0) {
				if ($altInfo->main == $name) {
					$alt = " <highlight>::<end> [" . $this->text->makeChatcmd("alts", "/tell <myname> alts $name") . "]";
				} else {
					$alt = " <highlight>::<end> " . $this->text->makeChatcmd("Alts of {$altInfo->main}", "/tell <myname> alts $name");
				}
			}

			$link .= "<tab>- {$name}{$info} {$alt}\n";
		}
		$msg = $this->text->makeBlob("Players Attending Event $id ($numAttendees)", $link);

		$context->reply($msg);
	}

	/** @return null|string[] */
	public function getEvents(): ?array {
		/** @var Collection<EventModel> */
		$data = $this->db->table("events")
			->orderByDesc("event_date")
			->limit($this->numEventsShown)
			->asObj(EventModel::class);
		if ($data->count() === 0) {
			return null;
		}
		$upcomingTitle = "<header2>Upcoming Events<end>\n";
		$pastTitle = "<header2>Past Events<end>\n";
		$updated = 0;

		$upcomingEvents = "";
		$pastEvents = "";
		foreach ($data as $row) {
			if ($row->event_attendees == '') {
				$attendance = 0;
			} else {
				$attendance = count(explode(",", $row->event_attendees));
			}
			if ($updated < $row->time_submitted) {
				$updated = $row->time_submitted;
			}
			if (!isset($row->event_date) || $row->event_date > time()) {
				if (!isset($row->event_date)) {
					$upcoming = "<tab>Event Date: <highlight>&lt;Not yet set&gt;<end>\n";
				} else {
					$upcoming = "<tab>Event Date: <highlight>" . $this->util->date($row->event_date) . "<end>\n";
				}
				$upcoming .= "<tab>Event Name: <highlight>$row->event_name<end>     [Event ID $row->id]\n";
				$upcoming .= "<tab>Author: <highlight>$row->submitter_name<end>\n";
				$upcoming .= "<tab>Attendance: <highlight>" . $this->text->makeChatcmd("$attendance signed up", "/tell <myname> events list $row->id") . "<end>" .
					" [" . $this->text->makeChatcmd("join", "/tell <myname> events join $row->id") . "] [" .
					$this->text->makeChatcmd("leave", "/tell <myname> events leave $row->id") . "]\n";
				$upcoming .= "<tab>Description: <highlight>" . ($row->event_desc ?? "&lt;empty&gt;") . "<end>\n";
				$upcoming .= "<tab>Date Submitted: <highlight>" . $this->util->date($row->time_submitted) . "<end>\n\n";
				$upcomingEvents = $upcoming.$upcomingEvents;
			} else {
				$past =  "<tab>Event Date: <highlight>" . $this->util->date($row->event_date) . "<end>\n";
				$past .= "<tab>Event Name: <highlight>$row->event_name<end>     [Event ID $row->id]\n";
				$past .= "<tab>Author: <highlight>$row->submitter_name<end>\n";
				$past .= "<tab>Attendance: <highlight>" . $this->text->makeChatcmd("$attendance signed up", "/tell <myname> events list $row->id") . "<end>\n";
				$past .= "<tab>Description: <highlight>" . ($row->event_desc??"&lt;empty&gt;") . "<end>\n";
				$past .= "<tab>Date Submitted: <highlight>" . $this->util->date($row->time_submitted) . "<end>\n\n";
				$pastEvents .= $past;
			}
		}
		$link = "";
		if (strlen($upcomingEvents)) {
			$link .= $upcomingTitle.$upcomingEvents;
		}
		if (strlen($pastEvents)) {
			$link .= $pastTitle.$pastEvents;
		}
		if (!strlen($link)) {
			$link = "<i>More to come. Check back soon!</i>\n\n";
		}

		return (array)$this->text->makeBlob("Events" . " [Last updated " . $this->util->date($updated)."]", $link);
	}

	#[NCA\Event(
		name: "logOn",
		description: "Show events to org members logging on"
	)]
	public function logonEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender)
			|| !$this->chatBot->isReady()
			|| !isset($this->chatBot->guildmembers[$sender])
			|| !$this->hasRecentEvents()
		) {
			return;
		}
		$events = $this->getEvents();
		if (isset($events)) {
			$this->chatBot->sendMassTell($events, $sender);
		}
	}

	#[NCA\Event(
		name: "joinPriv",
		description: "Show events to characters joining the private channel"
	)]
	public function joinPrivEvent(AOChatEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender) || !$this->hasRecentEvents()) {
			return;
		}
		$events =$this->getEvents();
		if (!isset($events)) {
			return;
		}
		$this->chatBot->sendMassTell($events, $sender);
	}

	public function hasRecentEvents(): bool {
		$sevenDays = time() - (86400 * 7);
		return $this->db->table("events")
			->where("event_date", ">", $sevenDays)
			->exists();
	}

	/**
	 * @psalm-param callable(?string) $callback
	 */
	#[
		NCA\NewsTile(
			name: "events",
			description: "Shows upcoming events - if any",
			example:
				"<header2>Events [<u>see more</u>]<end>\n".
				"<tab>2021-10-31 <highlight>GSP Halloween Party<end>"
		)
	]
	public function eventsTile(string $sender, callable $callback): void {
		/** @var Collection<EventModel> */
		$data = $this->db->table("events")
			->whereNull("event_date")
			->orWhere("event_date", ">", time())
			->orderBy("event_date")
			->limit($this->numEventsShown)
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
