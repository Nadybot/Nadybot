<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Nadybot\Core\{
	AccessManager,
	CmdContext,
	CommandManager,
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
	 */
	public function executesqlCommand(CmdContext $context, string $sql): void {
		$sql = htmlspecialchars_decode($sql);

		try {
			$num_rows = $this->db->exec($sql);
			$msg = "$num_rows rows affected.";
		} catch (SQLException $e) {
			$msg = $this->text->makeBlob("SQL Error", $e->getMessage());
		}
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("querysql")
	 */
	public function querysqlCommand(CmdContext $context, string $sql): void {
		$sql = htmlspecialchars_decode($sql);

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
		$context->reply($msg);
	}
}
