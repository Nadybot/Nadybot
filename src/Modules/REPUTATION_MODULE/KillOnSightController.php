<?php declare(strict_types=1);

namespace Nadybot\Modules\REPUTATION_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	Nadybot,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'kos',
 *		accessLevel = 'guild',
 *		description = 'Shows the kill-on-sight list',
 *		help        = 'kos.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'kos add .+',
 *		accessLevel = 'guild',
 *		description = 'Adds a character to the kill-on-sight list',
 *		help        = 'kos.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'kos rem .+',
 *		accessLevel = 'mod',
 *		description = 'Removes a character from the kill-on-sight list',
 *		help        = 'kos.txt'
 *	)
 */
class KillOnSightController {

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
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/** @Setup */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, 'kos');
	}

	/**
	 * @HandlesCommand("kos")
	 * @Matches("/^kos$/i")
	 */
	public function kosListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sql = "SELECT * FROM kos ORDER BY name ASC";

		/** @var Kos[] */
		$entries = $this->db->fetchAll(Kos::class, $sql);
		$count = count($entries);

		if ($count === 0) {
			$msg = "There are no characters on the KOS list.";
			$sendto->reply($msg);
			return;
		}
		$blob = "<header2>Personae non gratae<end>\n";
		foreach ($entries as $entry) {
			$comment = "";
			if (!empty($entry->comment)) {
				$comment = " - $entry->comment";
			}
			
			$blob .= "<tab><highlight>$entry->name<end>$comment (added by $entry->submitter <highlight>" . $this->util->unixtimeToReadable(time() - $entry->dt) . "<end> ago)\n";
		}
		$msg = $this->text->makeBlob("Kill-On-Sight List ($count)", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("kos add .+")
	 * @Matches("/^kos add ([a-z0-9-]+)$/i")
	 * @Matches("/^kos add ([a-z0-9-]+) (.+)$/i")
	 */
	public function kosAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$charid = $this->chatBot->get_uid($name);
		
		if (!$charid) {
			$sendto->reply("Character <highlight>$name<end> does not exist.");
			return;
		}

		$sql = "SELECT * FROM kos WHERE name = ?";
		$row = $this->db->fetch(Kos::class, $sql, $name);

		if ($row !== null) {
			$msg = "Character <highlight>$name<end> is already on the Kill-On-Sight list.";
			$sendto->reply($msg);
			return;
		}
		$comment = "";
		if (isset($args[2])) {
			$comment = trim($args[2]);
		}
		
		$sql = "INSERT INTO kos (name, comment, submitter, dt) VALUES (?, ?, ?, ?)";
		$this->db->exec($sql, $name, $comment, $sender, time());
		$msg = "Character <highlight>$name<end> has been added to the Kill-On-Sight list.";
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("kos rem .+")
	 * @Matches("/^kos rem (.+)$/i")
	 */
	public function kosRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$sql = "SELECT * FROM kos WHERE name = ?";

		/** @var ?Kos */
		$row = $this->db->fetch(Kos::class, $sql, $name);

		if ($row === null) {
			$msg = "Character <highlight>$name<end> is not on the Kill-On-Sight list.";
		} else {
			$sql = "DELETE FROM kos WHERE name = ?";
			$this->db->exec($sql, $name);
			$msg = "Character <highlight>$name<end> has been removed from the Kill-On-Sight list.";
		}
		$sendto->reply($msg);
	}
}
