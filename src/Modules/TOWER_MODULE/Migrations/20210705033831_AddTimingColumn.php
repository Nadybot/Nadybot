<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class AddTimingColumn implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "tower_site";
		$db->schema()->table($table, function(Blueprint $table) {
			$table->unsignedSmallInteger("timing")->default(0);
		});
	}
}
