<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_10_27_08_45_01)]
class ConvertAriaTables implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		if ($db->getType() !== DB\Type::MySQL) {
			return;
		}

		$tables = $db->table('information_schema.TABLES')
			->where('TABLE_SCHEMA', $db->schema()->getConnection()->getDatabaseName())
			->where('ENGINE', 'Aria')
			->select('TABLE_NAME')
			->pluckStrings('TABLE_NAME');
		if ($tables->isEmpty()) {
			return;
		}
		$logger->info('Converting {num_tables} DB tables from Aria to InnoDB...', [
			'num_tables' => $tables->count(),
		]);
		$grammar = $db->schema()->getConnection()->getSchemaGrammar();
		foreach ($tables as $table) {
			$sql = 'ALTER TABLE ' . $grammar->wrapTable($table).
				' ENGINE=' . $grammar->wrap('innodb');
			$db->schema()->getConnection()->statement($sql);
		}
		$logger->info('Converting done');
	}
}
