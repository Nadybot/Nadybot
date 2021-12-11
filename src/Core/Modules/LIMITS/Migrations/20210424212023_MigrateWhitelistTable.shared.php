<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\LIMITS\Migrations;

use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class MigrateWhitelistTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		if (!$db->schema()->hasTable("whitelist")) {
			return;
		}
		$db->table('whitelist')
			->select("name", "added_by", "added_dt")
			->orderBy("added_dt")
			->asObj()
			->each(function(object $data) use ($db) {
				$db->table('rateignorelist')
					->insert(get_object_vars($data));
			});
		$db->schema()->drop("whitelist");
	}
}
