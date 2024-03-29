<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class ConvertAriaTables implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		if ($db->getType() !== $db::MYSQL) {
			return;
		}

		/** @var string[] */
		$tables = $db->table("information_schema.TABLES")
			->where("TABLE_SCHEMA", $db->schema()->getConnection()->getDatabaseName())
			->where("ENGINE", "Aria")
			->select("TABLE_NAME")
			->pluckStrings("TABLE_NAME")
			->toArray();
		if (empty($tables)) {
			return;
		}
		$logger->log("INFO", "Converting " . count($tables) . " DB tables from Aria to InnoDB...");
		$grammar = $db->schema()->getConnection()->getSchemaGrammar();
		foreach ($tables as $table) {
			$sql = "ALTER TABLE " . $grammar->wrapTable($table).
				" ENGINE=" . $grammar->wrap("innodb");
			$db->schema()->getConnection()->statement($sql);
		}
		$logger->log("INFO", "Converting done");
	}
}
