<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\Modules\ALTS\AltsController;
use Nadybot\Core\Text;

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
	public AltsController $altsController;
	
	/** @Setup */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "notes");
	}
	
	/**
	 * @HandlesCommand("notes")
	 * @Matches("/^notes$/i")
	 */
	public function notesListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$altInfo = $this->altsController->getAltInfo($sender);
		$main = $altInfo->getValidatedMain($sender);
		
		if ($main !== $sender) {
			// convert all notes to be assigned to the main
			$sql = "UPDATE notes SET owner = ? WHERE owner = ?";
			$this->db->exec($sql, $main, $sender);
		}

		$sql = "SELECT * FROM notes WHERE owner = ? ORDER BY added_by ASC, dt DESC";
		/** @var Note[] */
		$notes = $this->db->fetchAll(Note::class, $sql, $main);

		if (count($notes) === 0) {
			$msg = "No notes for $sender.";
			$sendto->reply($msg);
			return;
		}
		$blob = '';
		$current = '';
		$count = count($notes);
		foreach ($notes as $note) {
			if ($note->added_by !== $current) {
				$blob .= "\n<header2>{$note->added_by}<end>\n";
				$current = $note->added_by;
			}
			$remove = $this->text->makeChatcmd('Remove', "/tell <myname> notes rem $note->id");
			$blob .= "<tab>$remove $note->note\n\n";
		}
		$msg = $this->text->makeBlob("Notes for $sender ($count)", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("notes")
	 * @Matches("/^notes add (.+)$/i")
	 */
	public function notesAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$note = $args[1];

		$altInfo = $this->altsController->getAltInfo($sender);
		$main = $altInfo->getValidatedMain($sender);

		$this->db->exec(
			"INSERT INTO notes (owner, added_by, note, dt) ".
			"VALUES (?, ?, ?, ?)",
			$main,
			$sender,
			$note,
			time()
		);
		$msg = "Note added successfully.";

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
}
