<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use function Safe\json_encode;
use Illuminate\Database\Capsule\Manager as Capsule;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	SQLException,
	Text,
};

use ReflectionClass;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "querysql",
		accessLevel: "superadmin",
		description: "Run an SQL query and see the results"
	),
	NCA\DefineCommand(
		command: "executesql",
		accessLevel: "superadmin",
		description: "Execute an SQL statement"
	)
]
class SQLController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	/** Execute a non-select SQL command */
	#[NCA\HandlesCommand("executesql")]
	#[NCA\Help\Group("sql")]
	public function executesqlCommand(CmdContext $context, string $sql): void {
		$sql = htmlspecialchars_decode($sql);
		// I don't want to expose the API in any other way, so yeah...
		$refDB = new ReflectionClass($this->db);
		$refCap = $refDB->getProperty("capsule");
		$refCap->setAccessible(true);

		/** @var Capsule */
		$capsule = $refCap->getValue($this->db);

		try {
			$success = $capsule->getConnection()->unprepared($sql);
			if ($success) {
				$msg = "Query run successfully.";
			} else {
				$msg = "Query run successfully, but no rows affected.";
			}
		} catch (SQLException $e) {
			$msg = $this->text->makeBlob("SQL Error", $e->getMessage());
		}
		$context->reply($msg);
	}

	/** Execute a select SQL query */
	#[NCA\HandlesCommand("querysql")]
	#[NCA\Help\Group("sql")]
	public function querysqlCommand(CmdContext $context, string $sql): void {
		// I don't want to expose the API in any other way, so yeah...
		$refDB = new ReflectionClass($this->db);
		$refCap = $refDB->getProperty("capsule");
		$refCap->setAccessible(true);

		/** @var Capsule */
		$capsule = $refCap->getValue($this->db);
		$sql = htmlspecialchars_decode($sql);

		try {
			$data = $capsule->getConnection()->select($sql);
			$count = count($data);

			$blob = "";
			foreach ($data as $row) {
				$blob .= "<pagebreak><header2>Entry<end>\n";
				foreach ($row as $key => $value) {
					$blob .= "<tab><highlight>{$key}:<end> " . json_encode($value, JSON_UNESCAPED_SLASHES)."\n";
				}
				$blob .= "\n";
			}
			if (empty($data)) {
				$msg = "Results ({$count})";
			} else {
				$msg = $this->text->makeBlob("Results ({$count})", $blob);
			}
		} catch (SQLException $e) {
			$msg = $this->text->makeBlob("SQL Error", $e->getMessage());
		}
		$context->reply($msg);
	}
}
