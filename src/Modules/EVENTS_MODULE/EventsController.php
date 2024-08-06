<?php declare(strict_types=1);

namespace Nadybot\Modules\EVENTS_MODULE;

use function Safe\strtotime;

use InvalidArgumentException;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\ParamClass\PUuid;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	Events\JoinMyPrivEvent,
	Events\LogonEvent,
	ExportCharacter,
	ExporterInterface,
	ImporterInterface,
	ModuleInstance,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	ParamClass\PRemove,
	Text,
	Util,
};
use Psr\Log\LoggerInterface;
use Safe\Exceptions\DatetimeException;
use Throwable;

/**
 * @author Legendadv (RK2)
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\Exporter('events'),
	NCA\Importer('events', ExportEvent::class),
	NCA\DefineCommand(
		command: 'events',
		accessLevel: 'guest',
		description: 'View/Join/Leave events',
		alias: 'event',
	),
	NCA\DefineCommand(
		command: EventsController::CMD_EVENT_MANAGE,
		accessLevel: 'mod',
		description: 'Add/change or delete an event',
	),
]
class EventsController extends ModuleInstance implements ImporterInterface, ExporterInterface {
	public const CMD_EVENT_MANAGE = 'events add/change/delete';

	/** Maximum number of events shown */
	#[NCA\Setting\Number(options: [5, 10, 15, 20])]
	public int $numEventsShown = 5;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private AltsController $altsController;

	/** Show the five closest past and upcoming events */
	#[NCA\HandlesCommand('events')]
	public function eventsCommand(CmdContext $context): void {
		$msg = $this->getEvents();
		if ($msg === null) {
			$msg = 'No events entered yet.';
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
	public function eventsAddCommand(CmdContext $context, #[NCA\Str('add')] string $action, string $eventName): void {
		$event = new EventModel(
			time_submitted: time(),
			submitter_name: $context->char->name,
			event_name: $eventName,
			event_date: null,
		);
		$this->db->insert($event);
		$msg = "Event: '{$eventName}' was added [Event ID {$event->id}].";
		$context->reply($msg);
	}

	/** Delete an event */
	#[NCA\HandlesCommand(self::CMD_EVENT_MANAGE)]
	public function eventsRemoveCommand(CmdContext $context, PRemove $action, PUuid $id): void {
		$id = $id();
		$row = $this->getEvent($id);
		if ($row === null) {
			$msg = "Could not find an event with id {$id}.";
		} else {
			$this->db->table(EventModel::getTable())->where('id', $id)->delete();
			$msg = "Event with id {$id} has been deleted.";
		}
		$context->reply($msg);
	}

	/** Change the description of an event */
	#[NCA\HandlesCommand(self::CMD_EVENT_MANAGE)]
	public function eventsSetDescCommand(CmdContext $context, #[NCA\Str('setdesc')] string $action, PUuid $id, string $description): void {
		$id = $id();
		$row = $this->getEvent($id);
		if ($row === null) {
			$msg = "Could not find an event with id {$id}.";
		} else {
			$this->db->table(EventModel::getTable())
				->where('id', $id)
				->update(['event_desc' => $description]);
			$msg = "Description for event with id {$id} has been updated.";
		}
		$context->reply($msg);
	}

	/** Change the date of an event */
	#[NCA\HandlesCommand(self::CMD_EVENT_MANAGE)]
	public function eventsSetDateCommand(
		CmdContext $context,
		#[NCA\Str('setdate')] string $action,
		PUuid $id,
		string $date,
	): void {
		$id = $id();
		$row = $this->getEvent($id);
		if ($row === null) {
			$msg = "Could not find an event with id {$id}.";
		} else {
			try {
				$eventDate = strtotime($date);
			} catch (DatetimeException) {
				$context->reply("'<highlight>{$date}<end>' is not a valid date/time.");
				return;
			}
			$this->db->table(EventModel::getTable())
				->where('id', $id)
				->update(['event_date' => $eventDate]);
			$msg = "Date/Time for event with id {$id} has been updated.";
		}
		$context->reply($msg);
	}

	public function getEvent(\Stringable|string $id): ?EventModel {
		return $this->db->table(EventModel::getTable())
			->where('id', (string)$id)
			->asObj(EventModel::class)
			->first();
	}

	/** Join event #id */
	#[NCA\HandlesCommand('events')]
	public function eventsJoinCommand(CmdContext $context, #[NCA\Str('join')] string $action, PUuid $id): void {
		$id = $id();
		$row = $this->getEvent($id);
		if ($row === null) {
			$msg = "There is no event with id <highlight>{$id}<end>.";
			$context->reply($msg);
			return;
		}
		if (isset($row->event_date) && time() >= ($row->event_date + (3_600 * 3))) {
			$msg = 'You cannot join an event once it has already passed!';
			$context->reply($msg);
			return;
		}
		// cannot join an event after 3 hours past its starttime
		$attendees = $row->getAttendees();
		if (in_array($context->char->name, $attendees, true)) {
			$msg = 'You are already on the event list.';
			$context->reply($msg);
			return;
		}
		$attendees []= $context->char->name;
		$this->db->table(EventModel::getTable())
			->where('id', $id)
			->update(['event_attendees' => implode(',', $attendees)]);
		$msg = 'You have been added to the event.';
		$context->reply($msg);
	}

	/** Leave event #id */
	#[NCA\HandlesCommand('events')]
	public function eventsLeaveCommand(CmdContext $context, #[NCA\Str('leave')] string $action, PUuid $id): void {
		$id = $id();
		$row = $this->getEvent($id);
		if ($row === null) {
			$msg = "There is no event with id <highlight>{$id}<end>.";
			$context->reply($msg);
			return;
		}
		if (isset($row->event_date) && time() >= ($row->event_date + (3_600 * 3))) {
			$msg = 'You cannot leave an event once it has already passed!';
			$context->reply($msg);
			return;
		}
		$attendees = $row->getAttendees();
		if (!in_array($context->char->name, $attendees, true)) {
			$msg = 'You are not on the event list.';
			$context->reply($msg);
			return;
		}
		$attendees = array_diff($attendees, [$context->char->name]);
		$this->db->table(EventModel::getTable())
			->where('id', $id)
			->update(['event_attendees' => implode(',', $attendees)]);
		$msg = 'You have been removed from the event.';
		$context->reply($msg);
	}

	/** List all characters marked as joining event #id */
	#[NCA\HandlesCommand('events')]
	public function eventsListCommand(CmdContext $context, #[NCA\Str('list')] string $action, PUuid $id): void {
		$id = $id();
		$row = $this->getEvent($id);
		if ($row === null) {
			$msg = "Could not find event with id <highlight>{$id}<end>.";
			$context->reply($msg);
			return;
		}
		if (!isset($row->event_attendees) || !strlen($row->event_attendees)) {
			$msg = 'No one has signed up to attend this event.';
			$context->reply($msg);
			return;
		}
		$link = '[' . Text::makeChatcmd('join this event', "/tell <myname> events join {$id}").'] ';
		$link .= '[' . Text::makeChatcmd('leave this event', "/tell <myname> events leave {$id}")."]\n\n";

		$link .= "<header2>Currently planning to attend<end>\n";
		$eventlist = explode(',', $row->event_attendees);
		$numAttendees = count($eventlist);
		sort($eventlist);
		foreach ($eventlist as $key => $name) {
			$row = $this->playerManager->findInDb($name, $this->db->getDim());
			$info = '';
			if ($row !== null) {
				$info = ", <highlight>Lvl {$row->level} {$row->profession?->value}<end>";
			}

			$altInfo = $this->altsController->getAltInfo($name);
			$alt = '';
			if (count($altInfo->getAllValidatedAlts()) > 0) {
				if ($altInfo->main === $name) {
					$alt = Text::makeChatcmd('alts', "/tell <myname> alts {$name}");
				} else {
					$alt = Text::makeChatcmd("Alts of {$altInfo->main}", "/tell <myname> alts {$name}");
				}
			}

			$link .= "<tab>- {$name}{$info} :: [{$alt}]\n";
		}
		$msg = $this->text->makeBlob("Players Attending Event {$id} ({$numAttendees})", $link);

		$context->reply($msg);
	}

	/** @return ?list<string> */
	public function getEvents(): ?array {
		$data = $this->db->table(EventModel::getTable())
			->orderByDesc('event_date')
			->limit($this->numEventsShown)
			->asObj(EventModel::class);
		if ($data->count() === 0) {
			return null;
		}
		$upcomingTitle = "<header2>Upcoming Events<end>\n";
		$pastTitle = "<header2>Past Events<end>\n";
		$updated = 0;

		$upcomingEvents = '';
		$pastEvents = '';
		foreach ($data as $row) {
			if (!isset($row->event_attendees) || $row->event_attendees === '') {
				$attendance = 0;
			} else {
				$attendance = count(explode(',', $row->event_attendees));
			}
			if ($updated < $row->time_submitted) {
				$updated = $row->time_submitted;
			}
			if (!isset($row->event_date) || $row->event_date > time()) {
				if (!isset($row->event_date)) {
					$upcoming = "<tab>Event Date: <highlight>&lt;Not yet set&gt;<end>\n";
				} else {
					$upcoming = '<tab>Event Date: <highlight>' . Util::date($row->event_date) . "<end>\n";
				}
				$upcoming .= "<tab>Event Name: <highlight>{$row->event_name}<end>     [Event ID {$row->id}]\n";
				$upcoming .= "<tab>Author: <highlight>{$row->submitter_name}<end>\n";
				$upcoming .= '<tab>Attendance: <highlight>' . Text::makeChatcmd("{$attendance} signed up", "/tell <myname> events list {$row->id}") . '<end>' .
					' [' . Text::makeChatcmd('join', "/tell <myname> events join {$row->id}") . '] [' .
					Text::makeChatcmd('leave', "/tell <myname> events leave {$row->id}") . "]\n";
				$upcoming .= '<tab>Description: <highlight>' . ($row->event_desc ?? '&lt;empty&gt;') . "<end>\n";
				$upcoming .= '<tab>Date Submitted: <highlight>' . Util::date($row->time_submitted) . "<end>\n\n";
				$upcomingEvents = $upcoming.$upcomingEvents;
			} else {
				$past =  '<tab>Event Date: <highlight>' . Util::date($row->event_date) . "<end>\n";
				$past .= "<tab>Event Name: <highlight>{$row->event_name}<end>     [Event ID {$row->id}]\n";
				$past .= "<tab>Author: <highlight>{$row->submitter_name}<end>\n";
				$past .= '<tab>Attendance: <highlight>' . Text::makeChatcmd("{$attendance} signed up", "/tell <myname> events list {$row->id}") . "<end>\n";
				$past .= '<tab>Description: <highlight>' . ($row->event_desc??'&lt;empty&gt;') . "<end>\n";
				$past .= '<tab>Date Submitted: <highlight>' . Util::date($row->time_submitted) . "<end>\n\n";
				$pastEvents .= $past;
			}
		}
		$link = '';
		if (strlen($upcomingEvents)) {
			$link .= $upcomingTitle.$upcomingEvents;
		}
		if (strlen($pastEvents)) {
			$link .= $pastTitle.$pastEvents;
		}
		if (!strlen($link)) {
			$link = "<i>More to come. Check back soon!</i>\n\n";
		}

		return (array)$this->text->makeBlob('Events [Last updated ' . Util::date($updated).']', $link);
	}

	#[NCA\Event(
		name: LogonEvent::EVENT_MASK,
		description: 'Show events to org members logging on'
	)]
	public function logonEvent(LogonEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!$this->chatBot->isReady()
			|| !isset($this->chatBot->guildmembers[$sender])
			|| $eventObj->wasOnline !== false
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
		name: JoinMyPrivEvent::EVENT_MASK,
		description: 'Show events to characters joining the private channel'
	)]
	public function joinPrivEvent(JoinMyPrivEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!$this->hasRecentEvents()) {
			return;
		}
		$events = $this->getEvents();
		if (!isset($events)) {
			return;
		}
		$this->chatBot->sendMassTell($events, $sender);
	}

	public function hasRecentEvents(): bool {
		$sevenDays = time() - (86_400 * 7);
		return $this->db->table(EventModel::getTable())
			->where('event_date', '>', $sevenDays)
			->exists();
	}

	/** @psalm-param callable(?string) $callback */
	#[
		NCA\NewsTile(
			name: 'events',
			description: 'Shows upcoming events - if any',
			example: "<header2>Events [<u>see more</u>]<end>\n".
				'<tab>2021-10-31 <highlight>GSP Halloween Party<end>'
		)
	]
	public function eventsTile(string $sender): ?string {
		$data = $this->db->table(EventModel::getTable())
			->whereNull('event_date')
			->orWhere('event_date', '>', time())
			->orderBy('event_date')
			->limit($this->numEventsShown)
			->asObj(EventModel::class);
		if ($data->count() === 0) {
			return null;
		}
		$eventsLink = Text::makeChatcmd('see more', '/tell <myname> events');
		$blob = "<header2>Events [{$eventsLink}]<end>\n";
		$blob .= $data->map(static function (EventModel $event): string {
			return '<tab>' . ((isset($event->event_date) && $event->event_date > 0)
				? Util::date($event->event_date)
				: 'soon').
				": <highlight>{$event->event_name}<end>";
		})->join("\n");
		return $blob;
	}

	/** @return list<ExportEvent> */
	public function export(DB $db, LoggerInterface $logger): array {
		return $db->table(EventModel::getTable())
			->asObj(EventModel::class)
			->map(static function (EventModel $event): ExportEvent {
				$attendees = array_values(array_diff(explode(',', $event->event_attendees ?? ''), ['']));

				$data = new ExportEvent(
					createdBy: new ExportCharacter(name: $event->submitter_name),
					creationTime: $event->time_submitted,
					name: $event->event_name,
					startTime: $event->event_date,
					description: $event->event_desc,
					attendees: array_map(
						static fn (string $name): ExportCharacter => new ExportCharacter(name: $name),
						$attendees
					),
				);

				return $data;
			})->toList();
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_events} events', [
			'num_events' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all events');
			$db->table(EventModel::getTable())->truncate();
			foreach ($data as $event) {
				if (!($event instanceof ExportEvent)) {
					throw new InvalidArgumentException('EventsController::import() was called with wrong data format');
				}
				$attendees = [];
				foreach ($event->attendees??[] as $attendee) {
					$name = $attendee->tryGetName();
					if (isset($name)) {
						$attendees []= $name;
					}
				}
				$db->insert(new EventModel(
					time_submitted: $event->creationTime ?? time(),
					submitter_name: $event->createdBy?->tryGetName() ?? $this->config->main->character,
					event_name: $event->name,
					event_date: $event->startTime ?? null,
					event_desc: $event->description ?? null,
					event_attendees: implode(',', $attendees),
				));
			}
		} catch (Throwable $e) {
			$logger->error('{error}. Rolling back changes.', [
				'error' => rtrim($e->getMessage(), '.'),
				'exception' => $e,
			]);
			return;
		}
		$db->commit();
		$logger->notice('All events imported');
	}
}
