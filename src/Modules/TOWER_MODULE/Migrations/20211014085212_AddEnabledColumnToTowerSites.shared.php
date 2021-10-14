<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class AddEnabledColumnToTowerSites implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "tower_site";
		$db->table($table)->truncate();
		$db->schema()->table($table, function(Blueprint $table) {
			$table->unsignedSmallInteger("enabled")->default(1)->index();
		});
	}
}
