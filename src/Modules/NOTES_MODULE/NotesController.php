<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\{
	AOChatEvent,
	AccessManager,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	CommandAlias,
	CommandManager,
	DB,
	LoggerWrapper,
	ModuleInstance,
	Modules\ALTS\AltEvent,
	Modules\ALTS\AltsController,
	Modules\PREFERENCES\Preferences,
	Nadybot,
	ParamClass\PRemove,
	Text,
	UserStateEvent,
};

/**
 * @author Tyrence (RK2)
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Notes"),
	NCA\DefineCommand(
		command: "notes",
		accessLevel: "guild",
		description: "Displays, adds, or removes a note from your list",
		alias: "note"
	),
	NCA\DefineCommand(
		command: "reminders",
		accessLevel: "guild",
		description: "Displays, adds, or removes a reminder from your list",
		alias: "reminder"
	),
	NCA\DefineCommand(
		command: "reminderformat",
		accessLevel: "guild",
		description: "Displays or changes the reminder format for oneself",
	),
]
class NotesController extends ModuleInstance {
	public const FORMAT_GROUPED = 'grouped';
	public const FORMAT_INDIVIDUAL = 'individual';
	public const FORMAT_INDIVIDUAL2 = 'individual2';

	public const DEFAULT_REMINDER_FORMAT = self::FORMAT_INDIVIDUAL;

	public const VALID_FORMATS = [
		self::FORMAT_GROUPED,
		self::FORMAT_INDIVIDUAL,
		self::FORMAT_INDIVIDUAL2,
	];

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public Preferences $preferences;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** How to display reminder-links in notes */
	#[NCA\Setting\Options(options: [
		'off' => 0,
		'compact' => 1,
		'verbose' => 2,
	])]
	public int $reminderFormat = 2;

	#[NCA\Setup]
	public function setup(): void {
		$this->commandAlias->register($this->moduleName, "notes rem", "reminders rem");
	}

	/** Show all your notes */
	#[NCA\HandlesCommand("notes")]
	#[NCA\Help\Group("notes")]
	public function notesListCommand(CmdContext $context): void {
		$altInfo = $this->altsController->getAltInfo($context->char->name);
		$main = $altInfo->getValidatedMain($context->char->name);

		$this->assignNotesToMain($main, $context->char->name);

		$notes = $this->readNotes($main, false);
		$count = count($notes);

		if ($count === 0) {
			$msg = "No notes for {$context->char->name}.";
			$context->reply($msg);
			return;
		}
		$blob = $this->renderNotes($notes, $context->char->name);
		$msg = $this->text->makeBlob("Notes for {$context->char->name} ({$count})", $blob);
		$context->reply($msg);
	}

	/** Show all your notes with reminders */
	#[NCA\HandlesCommand("reminders")]
	#[NCA\Help\Group("notes")]
	public function remindersListCommand(CmdContext $context): void {
		$altInfo = $this->altsController->getAltInfo($context->char->name);
		$main = $altInfo->getValidatedMain($context->char->name);

		$this->assignNotesToMain($main, $context->char->name);

		$notes = $this->readNotes($main, true);
		$count = count($notes);

		if ($count === 0) {
			$msg = "No reminders for {$context->char->name}.";
			$context->reply($msg);
			return;
		}
		$blob = trim($this->renderNotes($notes, $context->char->name));
		$blob .= "\n\n<i>Reminders are sent every time you logon or enter the bot's ".
			"private channel.\n".
			"To change the format in which the bot sends reminders, ".
			"you can use the ".
			$this->text->makeChatcmd("!reminderformat", "/tell <myname> reminderformat").
			" command.</i>";
		$msg = $this->text->makeBlob("Reminders for {$context->char->name} ({$count})", $blob);
		$context->reply($msg);
	}

	/** Save a note into the database and return the id */
	public function saveNote(string $noteText, string $sender, int $reminder=0): int {
		$altInfo = $this->altsController->getAltInfo($sender);
		$main = $altInfo->getValidatedMain($sender);
		$note = new Note();
		$note->added_by = $sender;
		$note->owner = $main;
		$note->note = $noteText;
		$note->reminder = $reminder;

		return $this->db->insert("notes", $note);
	}

	/** Add a new note to your list */
	#[NCA\HandlesCommand("notes")]
	#[NCA\Help\Group("notes")]
	public function notesAddCommand(CmdContext $context, #[NCA\Str("add")] string $action, string $note): void {
		$this->saveNote($note, $context->char->name);
		$msg = "Note added successfully.";

		$context->reply($msg);
	}

	/** Add a note and be reminded about it on logon */
	#[NCA\HandlesCommand("reminders")]
	#[NCA\Help\Group("notes")]
	public function reminderAddCommand(
		CmdContext $context,
		#[NCA\StrChoice("add", "addall", "addself")] string $action,
		string $note
	): void {
		$reminder = Note::REMIND_ALL;
		if ($action === "addself") {
			$reminder = Note::REMIND_SELF;
		}
		$this->saveNote($note, $context->char->name, $reminder);
		$msg = "Reminder added successfully.";

		$context->reply($msg);
	}

	/** Remove a note from your list */
	#[NCA\HandlesCommand("notes")]
	#[NCA\Help\Group("notes")]
	public function notesRemoveCommand(CmdContext $context, PRemove $action, int $id): void {
		$altInfo = $this->altsController->getAltInfo($context->char->name);
		$main = $altInfo->getValidatedMain($context->char->name);

		$numRows = $this->db->table("notes")
			->where("id", $id)
			->where("owner", $main)
			->delete();
		if ($numRows === 0) {
			$msg = "Note could not be found or note does not belong to you.";
		} else {
			$msg = "Note deleted successfully.";
		}

		$context->reply($msg);
	}

	/** Change the reminder type of a note */
	#[NCA\HandlesCommand("reminders")]
	#[NCA\Help\Group("notes")]
	#[NCA\Help\Epilogue(
		"<header2>Reminder types<end>\n".
		"<tab>self: Be reminded only on the character who created the note/reminder\n".
		"<tab>all: Be reminded on all your alts\n".
		"<tab>off: Don't be reminded\n"
	)]
	public function reminderSetCommand(
		CmdContext $context,
		#[NCA\Str("set")] string $action,
		#[NCA\StrChoice("all", "self", "off")] string $type,
		int $id
	): void {
		$reminder = Note::REMIND_ALL;
		if ($type === "self") {
			$reminder = Note::REMIND_SELF;
		} elseif ($type === "off") {
			$reminder = Note::REMIND_NONE;
		}
		$altInfo = $this->altsController->getAltInfo($context->char->name);
		$main = $altInfo->getValidatedMain($context->char->name);
		$updated = $this->db->table("notes")
			->where("id", $id)
			->where("owner", $main)
			->update(["reminder" => $reminder]);
		if (!$updated) {
			$context->reply("No note or reminder #{$id} found for you.");
			return;
		}
		$msg = "Reminder changed successfully.";
		$context->reply($msg);
	}

	#[NCA\Event(
		name: "logOn",
		description: "Sends a tell to players on logon showing their reminders"
	)]
	public function showRemindersOnLogonEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender) || isset($this->chatBot->chatlist[$sender])) {
			return;
		}
		$this->showReminders($sender);
	}

	#[NCA\Event(
		name: "joinPriv",
		description: "Show reminders when joining the private channel"
	)]
	public function showRemindersOnPrivJoinEvent(AOChatEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender) || $this->buddylistManager->isOnline($sender)) {
			return;
		}
		$this->showReminders($sender);
	}

	public function getReminderFormat(string $sender): string {
		$altInfo = $this->altsController->getAltInfo($sender);
		$main = $altInfo->getValidatedMain($sender);
		$reminderFormat = $this->preferences->get($main, 'reminder_format');
		if ($reminderFormat === null || $reminderFormat === '') {
			$reminderFormat = static::DEFAULT_REMINDER_FORMAT;
		}
		return $reminderFormat;
	}

	#[NCA\Event(
		name: "alt(newmain)",
		description: "Move reminder format to new main"
	)]
	public function moveReminderFormat(AltEvent $event): void {
		$reminderFormat = $this->preferences->get($event->alt, 'reminder_format');
		if ($reminderFormat === null || $reminderFormat === '') {
			return;
		}
		$this->preferences->delete($event->alt, 'reminder_format');
		$this->preferences->save($event->main, 'reminder_format', $reminderFormat);
		$this->logger->notice("Moved reminder format from {$event->alt} to {$event->main}.");
	}

	/**
	 * Render the reminder message for $sender, reminding about the $notes
	 *
	 * @param string $format The format to render the messages with
	 * @param Note[] $notes  The notes we are reminded about
	 *
	 * @return string The rendered message
	 */
	public function getReminderMessage(string $format, array $notes): string {
		if ($format === static::FORMAT_GROUPED) {
			$msgs = array_map(
				function (Note $note): string {
					return "For {$note->added_by}: <highlight>{$note->note}<end>";
				},
				$notes
			);
			$msg = ":: <red>Reminder" . (count($msgs) > 1 ? "s" : "") . "<end> ::\n".
				join("\n", $msgs);
		} else {
			$msgs = array_map(
				function (Note $note) use ($format): string {
					$addedBy = $note->added_by;
					if ($format === static::FORMAT_INDIVIDUAL2) {
						$addedBy = "<yellow>{$addedBy}<end>";
					}
					return ":: <red>Reminder for {$addedBy}<end> :: <highlight>{$note->note}<end>";
				},
				$notes
			);
			$msg = join("\n", $msgs);
		}
		return $msg;
	}

	/** Show the format of your reminder */
	#[NCA\HandlesCommand("reminderformat")]
	#[NCA\Help\Group("notes")]
	public function reminderformatShowCommand(CmdContext $context): void {
		$reminderFormat = $this->getReminderFormat($context->char->name);
		$exampleNote1 = new Note();
		$exampleNote1->added_by = $context->char->name;
		$exampleNote1->note = "Example text about something super important";
		$exampleNote2 = new Note();
		$exampleNote2->added_by = "Nadyita";
		$exampleNote2->note = "Don't forget to buy grenades!";
		$exampleNotes = [$exampleNote1, $exampleNote2];
		$formats = static::VALID_FORMATS;
		$blob = "When you logon or enter the bot's private channel, the bot will\n".
			"send you a tell with all your reminders.\n\n".
			"You can choose between one of the following formats what this tell\n".
			"should look like:\n\n";
		foreach ($formats as $format) {
			$useThisLinks = $this->text->makeChatcmd(
				"use this",
				"/tell <myname> reminderformat {$format}"
			);
			$blob .= "<header2>".
				"{$format} [{$useThisLinks}]".
				(($reminderFormat === $format) ? " (<highlight>active<end>)" : "").
				"<end>\n";
			$example = join("\n<tab>", explode("\n", $this->getReminderMessage($format, $exampleNotes)));
			$blob .= "<tab>{$example}\n\n";
		}
		$blob .= "\n<i>Your reminder format preference is the same for all of your alts</i>.";

		$blobLink = $this->text->makeBlob("Details", $blob, "The available reminder formats");
		$msg = $this->text->blobWrap(
			"Your reminder format is <highlight>{$reminderFormat}<end> :: [",
			$blobLink,
			"]"
		);
		$context->reply($msg);
	}

	/** Change the format of your reminder */
	#[NCA\HandlesCommand("reminderformat")]
	#[NCA\Help\Group("notes")]
	public function reminderformatChangeCommand(CmdContext $context, string $format): void {
		$format = strtolower($format);
		$formats = static::VALID_FORMATS;
		if (!in_array($format, $formats, true)) {
			$formats = $this->text->arraySprintf("<highlight>%s<end>", ...$formats);
			$formatString = $this->text->enumerate(...$formats);
			$context->reply("Valid options are {$formatString}.");
			return;
		}
		$altInfo = $this->altsController->getAltInfo($context->char->name);
		$main = $altInfo->getValidatedMain($context->char->name);
		$this->preferences->save($main, 'reminder_format', $format);
		$context->reply("Your reminder format has been set to <highlight>{$format}<end>.");
	}

	#[
		NCA\NewsTile(
			name: "notes",
			description: "Shows you how many notes you have for this character\n".
				"as well with a link to show them",
			example: "<header2>Notes<end>\n".
				"<tab>You have <highlight>2 notes<end> [<u>show</u>]"
		)
	]
	public function notesNewsTile(string $sender, callable $callback): void {
		$altInfo = $this->altsController->getAltInfo($sender);
		$main = $altInfo->getValidatedMain($sender);

		$this->assignNotesToMain($main, $sender);

		$notes = $this->readNotes($main, false);
		$count = count($notes);

		if ($count === 0) {
			$callback(null);
			return;
		}
		$blob = "<header2>Notes<end>\n".
			"<tab>You have <highlight>{$count} ".
			$this->text->pluralize("note", $count).
			"<end> [".
			$this->text->makeChatcmd("show", "/tell <myname> notes").
			"]";
		$callback($blob);
	}

	/** Make sure all notes with owner $sender are assigned to the main */
	protected function assignNotesToMain(string $main, string $sender): void {
		if ($main === $sender) {
			return;
		}
		// convert all notes to be assigned to the main
		$this->db->table("notes")
			->where("owner", $sender)
			->update(["owner" => $main]);
	}

	/**
	 * Read all notes or reminders for player $main
	 *
	 * @return Note[]
	 */
	protected function readNotes(string $main, bool $remindersOnly=false): array {
		return $this->db->table("notes")
			->where("owner", $main)
			->where("reminder", ">", $remindersOnly ? 0 : -1)
			->orderBy("added_by")
			->orderByDesc("dt")
			->asObj(Note::class)->toArray();
	}

	/**
	 * Render an array of Notes into a blob
	 *
	 * @param Note[] $notes
	 */
	protected function renderNotes(array $notes, string $sender): string {
		$blob = '';
		$current = '';
		$format = $this->reminderFormat;
		if (!$this->commandManager->cmdExecutable('reminders', $sender)) {
			$format = 0;
		}
		foreach ($notes as $note) {
			if ($note->added_by !== $current) {
				$blob .= "\n<header2>{$note->added_by}<end>\n";
				$current = $note->added_by;
			}
			$deleteLink = $this->text->makeChatcmd('remove', "/tell <myname> notes rem {$note->id}");

			$reminderLinks = $this->renderReminderLinks($note, $format);
			if ($format === 0) {
				$blob .= "<tab>[{$deleteLink}] {$note->note}\n\n";
			} elseif ($format === 1) {
				$blob .= "<tab>[{$deleteLink}] {$note->note}<tab>{$reminderLinks}\n\n";
			} else {
				$blob .= "<tab>- <highlight>{$note->note}<end> [{$deleteLink}]<tab>Reminders: {$reminderLinks}\n\n";
			}
		}
		return $blob;
	}

	protected function renderReminderLinks(Note $note, int $format): string {
		if ($format === 0) {
			return "";
		}
		$texts = [
			1 => ["O", "S", "A"],
			2 => ["off", "self", "all"],
		];
		$labels = $texts[$format];
		$links = [];
		$remindOffLink  = $this->text->makeChatcmd(
			$labels[Note::REMIND_NONE],
			"/tell <myname> reminders set off {$note->id}"
		);
		$remindSelfLink = $this->text->makeChatcmd(
			$labels[Note::REMIND_SELF],
			"/tell <myname> reminders set self {$note->id}"
		);
		$remindAllLink  = $this->text->makeChatcmd(
			$labels[Note::REMIND_ALL],
			"/tell <myname> reminders set all {$note->id}"
		);
		if (($note->reminder & Note::REMIND_ALL) === 0) {
			$links []= $remindAllLink;
		} else {
			$links []= "<on>{$labels[2]}<end>";
		}
		if (($note->reminder & Note::REMIND_SELF) === 0) {
			$links []= $remindSelfLink;
		} else {
			$links []= "<yellow>{$labels[1]}<end>";
		}
		if ($note->reminder !== Note::REMIND_NONE) {
			$links []= $remindOffLink;
		} else {
			$links []= "<off>{$labels[0]}<end>";
		}
		if ($format === 1) {
			return "(" . join("|", $links) . ")";
		}
		return "[" . join("] [", $links) . "]";
	}

	/** Show all reminder for character $sender */
	protected function showReminders(string $sender): void {
		$altInfo = $this->altsController->getAltInfo($sender);
		$main = $altInfo->getValidatedMain($sender);
		$notes = $this->readNotes($main, true);
		$notes = array_filter(
			$notes,
			function (Note $note) use ($sender): bool {
				return $note->reminder === Note::REMIND_ALL || $note->added_by === $sender;
			}
		);
		if (!count($notes)) {
			return;
		}
		$reminderFormat = $this->getReminderFormat($sender);
		$msg = $this->getReminderMessage($reminderFormat, $notes);
		$this->chatBot->sendMassTell($msg, $sender);
	}
}
