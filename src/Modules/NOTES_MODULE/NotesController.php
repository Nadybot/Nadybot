<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\{
	AOChatEvent,
	AccessManager,
	BuddylistManager,
	CommandReply,
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
		$this->db->loadSQLFile($this->moduleName, "notes");
		if (!$this->db->columnExists("notes", "reminder")) {
			$this->db->exec("ALTER TABLE `notes` ADD COLUMN `reminder` INTEGER NOT NULL DEFAULT 0");
		}
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
	 * @Matches("/^notes$/i")
	 */
	public function notesListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$altInfo = $this->altsController->getAltInfo($sender);
		$main = $altInfo->getValidatedMain($sender);

		$this->assignNotesToMain($main, $sender);

		$notes = $this->readNotes($main, false);
		$count = count($notes);

		if ($count === 0) {
			$msg = "No notes for $sender.";
			$sendto->reply($msg);
			return;
		}
		$blob = $this->renderNotes($notes, $sender);
		$msg = $this->text->makeBlob("Notes for $sender ($count)", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("reminders")
	 * @Matches("/^reminders$/i")
	 */
	public function remindersListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$altInfo = $this->altsController->getAltInfo($sender);
		$main = $altInfo->getValidatedMain($sender);

		$this->assignNotesToMain($main, $sender);

		$notes = $this->readNotes($main, true);
		$count = count($notes);

		if ($count === 0) {
			$msg = "No reminders for $sender.";
			$sendto->reply($msg);
			return;
		}
		$blob = $this->renderNotes($notes, $sender);
		$blob .= "\n\nReminders are sent every time you logon or enter the bot's ".
			"private channel.\n".
			"To change the format in which the bot sends reminders, ".
			"you can use the ".
			$this->text->makeChatcmd("!reminderformat", "/tell <myname> reminderformat").
			" command.";
		$msg = $this->text->makeBlob("Reminders for $sender ($count)", $blob);
		$sendto->reply($msg);
	}

	/**
	 * Make sure all notes with owner $sender are assigned to the main
	 */
	protected function assignNotesToMain(string $main, string $sender): void {
		if ($main === $sender) {
			return;
		}
		// convert all notes to be assigned to the main
		$sql = "UPDATE `notes` SET `owner` = ? WHERE `owner` = ?";
		$this->db->exec($sql, $main, $sender);
	}

	/**
	 * Read all notes or reminders for player $main
	 *
	 * @return Note[]
	 */
	protected function readNotes(string $main, bool $remindersOnly=false): array {
		$sql = "SELECT * FROM `notes` WHERE `owner` = ? AND `reminder` > ? ".
			"ORDER BY `added_by` ASC, `dt` DESC";
		/** @var Note[] */
		$notes = $this->db->fetchAll(Note::class, $sql, $main, $remindersOnly ? 0 : -1);
		return $notes;
	}

	/**
	 * Render an array of Notes into a blob
	 *
	 * @param Note[] $notes
	 */
	protected function renderNotes(array $notes, string $sender): string {
		$blob = '';
		$current = '';
		$format = $this->settingManager->getInt('reminder_format');
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
			$deleteLink = $this->text->makeChatcmd('Remove', "/tell <myname> notes rem {$note->id}");

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
			2 => ["Off", "Self", "All"],
		];
		$labels = $texts[$format];
		$links = [];
		$remindOffLink  = $this->text->makeChatcmd(
			$labels[Note::REMIND_NONE], "/tell <myname> reminders set off {$note->id}"
		);
		$remindSelfLink = $this->text->makeChatcmd(
			$labels[Note::REMIND_SELF], "/tell <myname> reminders set self {$note->id}"
		);
		$remindAllLink  = $this->text->makeChatcmd(
			$labels[Note::REMIND_ALL], "/tell <myname> reminders set all {$note->id}"
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
	 * @Matches("/^notes add (.+)$/i")
	 */
	public function notesAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$noteText = $args[1];

		$this->saveNote($noteText, $sender);
		$msg = "Note added successfully.";

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("reminders")
	 * @Matches("/^reminders (add|addall|addself) (.+)$/i")
	 */
	public function reminderAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$type = $args[1];
		$noteText = $args[2];

		$reminder = Note::REMIND_ALL;
		if ($type === "addself") {
			$reminder = Note::REMIND_SELF;
		}
		$this->saveNote($noteText, $sender, $reminder);
		$msg = "Reminder added successfully.";

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("notes")
	 * @Matches("/^notes rem (\d+)$/i")
	 */
	public function notesRemoveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];

		$altInfo = $this->altsController->getAltInfo($sender);
		$main = $altInfo->getValidatedMain($sender);

		$numRows = $this->db->exec(
			"DELETE FROM notes WHERE id = ? AND owner = ?",
			$id,
			$main
		);
		if ($numRows === 0) {
			$msg = "Note could not be found or note does not belong to you.";
		} else {
			$msg = "Note deleted successfully.";
		}

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("reminders")
	 * @Matches("/^reminders set (all|self|off) (\d+)$/i")
	 */
	public function reminderSetCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$type = $args[1];
		$id = (int)$args[2];

		$reminder = Note::REMIND_ALL;
		if ($type === "self") {
			$reminder = Note::REMIND_SELF;
		} elseif ($type === "off") {
			$reminder = Note::REMIND_NONE;
		}
		$altInfo = $this->altsController->getAltInfo($sender);
		$main = $altInfo->getValidatedMain($sender);
		$updated = $this->db->exec(
			"UPDATE `notes` SET `reminder` = ? WHERE `id` = ? AND `owner` = ?",
			$reminder,
			$id,
			$main
		);
		if (!$updated) {
			$sendto->reply("No note or reminder #{$id} found for you.");
			return;
		}
		$msg = "Reminder changed successfully.";
		$sendto->reply($msg);
	}

	/**
	 * @Event("logOn")
	 * @Description("Sends a tell to players on logon showing their reminders")
	 */
	public function showRemindersOnLogonEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (isset($this->chatBot->chatlist[$sender])) {
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
		if ($this->buddylistManager->isOnline($sender)) {
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
		$this->logger->log('INFO', "Moved reminder format from {$event->alt} to {$event->main}.");
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
	 * @Matches("/^reminderformat$/i")
	 */
	public function reminderformatShowCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$reminderFormat = $this->getReminderFormat($sender);
		$exampleNote1 = new Note();
		$exampleNote1->added_by = $sender;
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
		$msg = "Your reminder format is <highlight>{$reminderFormat}<end> :: [{$blobLink}]";
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("reminderformat")
	 * @Matches("/^reminderformat\s+(.+)$/i")
	 */
	public function reminderformatChangeCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$format = strtolower($args[1]);
		$formats = static::VALID_FORMATS;
		if (!in_array($format, $formats, true)) {
			$formats = $this->text->arraySprintf("<highlight>%s<end>", ...$formats);
			$formatString = $this->text->enumerate(...$formats);
			$sendto->reply("Valid options are {$formatString}.");
			return;
		}
		$altInfo = $this->altsController->getAltInfo($sender);
		$main = $altInfo->getValidatedMain($sender);
		$this->preferences->save($main, 'reminder_format', $format);
		$sendto->reply("Your reminder format has been set to <highlight>{$format}<end>.");
	}
}
