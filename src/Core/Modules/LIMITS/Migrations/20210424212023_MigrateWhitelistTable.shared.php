<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\LIMITS\Migrations;

use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use stdClass;

class MigrateWhitelistTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		if (!$db->schema()->hasTable("whitelist")) {
			return;
		}
		$db->table('whitelist')
			->select("name", "added_by", "added_dt")
			->orderBy("added_dt")
			->get()
			->each(function (stdClass $data) use ($db) {
				$db->table('rateignorelist')
					->insert([
						"name" => (string)$data->name,
						"added_by" => (string)$data->added_by,
						"added_dt" => (int)$data->added_dt,
					]);
			});
		$db->schema()->drop("whitelist");
	}
}
