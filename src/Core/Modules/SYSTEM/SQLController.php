<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Nadybot\Core\{
	AccessManager,
	CommandManager,
	CommandReply,
	DB,
	SQLException,
	Text,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command       = 'loadsql',
 *		accessLevel   = 'admin',
 *		description   = 'Manually reload an SQL file',
 *		help          = 'loadsql.txt'
 *	)
 *	@DefineCommand(
 *		command       = 'querysql',
 *		accessLevel   = 'superadmin',
 *		description   = 'Run an SQL query and see the results'
 *	)
 *	@DefineCommand(
 *		command       = 'executesql',
 *		accessLevel   = 'superadmin',
 *		description   = 'Execute an SQL statement'
 *	)
 */
class SQLController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public AccessManager $accessManager;
	
	/** @Inject */
	public DB $db;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public Text $text;

	/**
	 * @HandlesCommand("executesql")
	 * @Matches("/^executesql (.*)$/i")
	 */
	public function executesqlCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sql = htmlspecialchars_decode($args[1]);

		try {
			$num_rows = $this->db->exec($sql);
			$msg = "$num_rows rows affected.";
		} catch (SQLException $e) {
			$msg = $this->text->makeBlob("SQL Error", $e->getMessage());
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("querysql")
	 * @Matches("/^querysql (.*)$/si")
	 */
	public function querysqlCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sql = htmlspecialchars_decode($args[1]);

		try {
			$data = $this->db->query($sql);
			$count = count($data);

			$blob = "";
			foreach ($data as $row) {
				$blob .= "<pagebreak><header2>Entry<end>\n";
				foreach ($row as $key => $value) {
					$blob .= "<tab><highlight>$key:<end> ".json_encode($value, JSON_UNESCAPED_SLASHES)."\n";
				}
				$blob .= "\n";
			}
			$msg = $this->text->makeBlob("Results ($count)", $blob);
		} catch (SQLException $e) {
			$msg = $this->text->makeBlob("SQL Error", $e->getMessage());
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("loadsql")
	 * @Matches("/^loadsql ([^ ]+) (.+)$/i")
	 */
	public function loadsqlCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$module = strtoupper($args[1]);
		$name = strtolower($args[2]);

		$this->db->beginTransaction();
		$msg = $this->db->loadSQLFile($module, $name, true);
		$this->db->commit();

		$sendto->reply($msg);
	}
}
