<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\{
	AOChatEvent,
	AccessManager,
	BuddylistManager,
	CmdContext,
	CommandAlias,
	CommandManager,
	DB,
	LoggerWrapper,
	Modules\ALTS\AltsController,
	Nadybot,
	SettingManager,
	Text,
	UserStateEvent,
};
use Nadybot\Core\Annotations\Logger;
use Nadybot\Core\Modules\ALTS\AltEvent;
use Nadybot\Core\Modules\PREFERENCES\Preferences;
use Nadybot\Core\ParamClass\PRemove;

/**
 * @author Tyrence (RK2)
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'notes',
 *		accessLevel = 'guild',
 *		description = 'Displays, adds, or removes a note from your list',
 *		help        = 'notes.txt',
 *		alias       = 'note'
 *	)
 *	@DefineCommand(
 *		command     = 'reminders',
 *		accessLevel = 'guild',
 *		description = 'Displays, adds, or removes a reminder from your list',
 *		help        = 'notes.txt',
 *		alias       = 'reminder'
 *	)
 *	@DefineCommand(
 *		command     = 'reminderformat',
 *		accessLevel = 'guild',
 *		description = 'Displays or changes the reminder format for oneself',
 *		help        = 'notes.txt'
 *	)
 */
class NotesController {
	public const FORMAT_GROUPED = 'grouped';
	public const FORMAT_INDIVIDUAL = 'individual';
	public const FORMAT_INDIVIDUAL2 = 'individual2';

	public const DEFAULT_REMINDER_FORMAT = self::FORMAT_INDIVIDUAL;

	public const VALID_FORMATS = [
		self::FORMAT_GROUPED,
		self::FORMAT_INDIVIDUAL,
		self::FORMAT_INDIVIDUAL2,
	];

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public Preferences $preferences;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Setup */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Notes");
		$this->commandAlias->register($this->moduleName, "notes rem", "reminders rem");
		$this->settingManager->add(
			$this->moduleName,
			"reminder_format",
			"How to display reminder-links in notes",
			"edit",
			"options",
			"2",
			"off;compact;verbose",
			'0;1;2',
			"mod"
		);
	}

	/**
	 * @HandlesCommand("notes")
	 */
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

	/**
	 * @HandlesCommand("reminders")
	 */
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

	/**
	 * Make sure all notes with owner $sender are assigned to the main
	 */
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
		$format = $this->settingManager->getInt('reminder_format') ?? 2;
		$reminderCommands = $this->commandManager->get('reminders', 'msg');
		// If the command is not available to the sender, don't render reminder-links
		if (empty($reminderCommands)
			|| !$reminderCommands[0]->status
			|| !$this->accessManager->checkAccess($sender, $reminderCommands[0]->admin)
		) {
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
				$blob .= "<tab>[$deleteLink] {$note->note}<tab>{$reminderLinks}\n\n";
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
			$links []= "<green>{$labels[2]}<end>";
		}
		if (($note->reminder & Note::REMIND_SELF) === 0) {
			$links []= $remindSelfLink;
		} else {
			$links []= "<yellow>{$labels[1]}<end>";
		}
		if ($note->reminder !== Note::REMIND_NONE) {
			$links []= $remindOffLink;
		} else {
			$links []= "<red>{$labels[0]}<end>";
		}
		if ($format === 1) {
			return "(" . join("|", $links) . ")";
		}
		return "[" . join("] [", $links) . "]";
	}

	/**
	 * Save a note into the database and return the id
	 */
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

	/**
	 * @HandlesCommand("notes")
	 * @Mask $action add
	 */
	public function notesAddCommand(CmdContext $context, string $action, string $note): void {
		$this->saveNote($note, $context->char->name);
		$msg = "Note added successfully.";

		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("reminders")
	 * @Mask $action (add|addall|addself)
	 */
	public function reminderAddCommand(CmdContext $context, string $action, string $note): void {
		$reminder = Note::REMIND_ALL;
		if ($action === "addself") {
			$reminder = Note::REMIND_SELF;
		}
		$this->saveNote($note, $context->char->name, $reminder);
		$msg = "Reminder added successfully.";

		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("notes")
	 */
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

	/**
	 * @HandlesCommand("reminders")
	 * @Mask $action set
	 * @Mask $type (all|self|off)
	 */
	public function reminderSetCommand(CmdContext $context, string $action, string $type, int $id): void {
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

	/**
	 * @Event("logOn")
	 * @Description("Sends a tell to players on logon showing their reminders")
	 */
	public function showRemindersOnLogonEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender) || isset($this->chatBot->chatlist[$sender])) {
			return;
		}
		$this->showReminders($sender);
	}

	/**
	 * @Event("joinPriv")
	 * @Description("Show reminders when joining the private channel")
	 */
	public function showRemindersOnPrivJoinEvent(AOChatEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender) || $this->buddylistManager->isOnline($sender)) {
			return;
		}
		$this->showReminders($sender);
	}

	/**
	 * Show all reminder for character $sender
	 */
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

	public function getReminderFormat(string $sender): string {
		$altInfo = $this->altsController->getAltInfo($sender);
		$main = $altInfo->getValidatedMain($sender);
		$reminderFormat = $this->preferences->get($main, 'reminder_format');
		if ($reminderFormat === null || $reminderFormat === '') {
			$reminderFormat = static::DEFAULT_REMINDER_FORMAT;
		}
		return $reminderFormat;
	}

	/**
	 * @Event("alt(newmain)")
	 * @Description("Move reminder format to new main")
	 */
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
	 * @param string $sender Person being reminded
	 * @param Note[] $notes The notes we are reminded about
	 * @return string The rendered message
	 */
	public function getReminderMessage(string $format, array $notes): string {
		if ($format === static::FORMAT_GROUPED) {
			$msgs = array_map(
				function (Note $note): string {
					return "For {$note->added_by}: <highlight>$note->note<end>";
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
		return  $msg;
	}

	/**
	 * @HandlesCommand("reminderformat")
	 */
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

	/**
	 * @HandlesCommand("reminderformat")
	 */
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

	/**
	 * @NewsTile("notes")
	 * @Description("Shows you how many notes you have for this character
	 * as well with a link to show them")
	 * @Example("<header2>Notes<end>
	 * <tab>You have <highlight>2 notes<end> [<u>show</u>]")
	 */
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
}
