<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\{
	AOChatEvent,
	BuddylistManager,
	CommandReply,
	CommandAlias,
	DB,
	Modules\ALTS\AltsController,
	Nadybot,
	Text,
	UserStateEvent,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'notes',
 *		accessLevel = 'guild',
 *		description = 'Displays, adds, or removes a note from your list',
 *		help        = 'notes.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'reminders',
 *		accessLevel = 'guild',
 *		description = 'Displays, adds, or removes a reminder from your list',
 *		help        = 'notes.txt'
 *	)
 */
class NotesController {

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
	public BuddylistManager $buddylistManager;

	/** @Setup */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "notes");
		if (!$this->db->columnExists("notes", "reminder")) {
			$this->db->exec("ALTER TABLE `notes` ADD COLUMN `reminder` INTEGER NOT NULL DEFAULT 0");
		}
		$this->commandAlias->register($this->moduleName, "notes", "note");
		$this->commandAlias->register($this->moduleName, "reminders", "reminder");
		$this->commandAlias->register($this->moduleName, "notes rem", "reminders rem");
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
		$blob = $this->renderNotes($notes);
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
		$blob = $this->renderNotes($notes);
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
		$sql = "UPDATE notes SET owner = ? WHERE owner = ?";
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
	protected function renderNotes(array $notes): string {
		$blob = '';
		$current = '';
		foreach ($notes as $note) {
			if ($note->added_by !== $current) {
				$blob .= "\n<header2>{$note->added_by}<end>\n";
				$current = $note->added_by;
			}
			$links = [];
			$links []= $this->text->makeChatcmd('Remove', "/tell <myname> notes rem {$note->id}");
			$remindAllLink = $this->text->makeChatcmd('All', "/tell <myname> reminders set all {$note->id}");
			$remindSelfLink = $this->text->makeChatcmd('Self', "/tell <myname> reminders set self {$note->id}");
			$remindOffLink = $this->text->makeChatcmd('Off', "/tell <myname> reminders set off {$note->id}");
			if (($note->reminder & 2) === 0) {
				$links []= $remindAllLink;
			}
			if (($note->reminder & 1) === 0) {
				$links []= $remindSelfLink;
			}
			if ($note->reminder > 0) {
				$links []= $remindOffLink;
			}

			$blob .= "<tab>$note->note";
			if ($note->reminder === 1) {
				$blob .= " (<highlight>self<end>)";
			} elseif ($note->reminder === 2) {
				$blob .= " (<highlight>all<end>)";
			}
			$blob .= " [" . join("] [", $links) . "]\n\n";
		}
		return $blob;
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

		$reminder = 2;
		if ($type === "addself") {
			$reminder = 1;
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

		$reminder = 2;
		if ($type === "self") {
			$reminder = 1;
		} elseif ($type === "off") {
			$reminder = 0;
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
				return $note->reminder === 2 || $note->added_by === $sender;
			}
		);
		if (!count($notes)) {
			return;
		}
		$msgs = array_map(
			function (Note $note): string {
				return "For {$note->added_by}: <highlight>$note->note<end>";
			},
			$notes
		);
		$msg = ":: <red>Reminder" . (count($msgs) > 1 ? "s" : "") . "<end> ::\n".
			join("\n", $msgs);
		$this->chatBot->sendMassTell($msg, $sender);
	}
}
