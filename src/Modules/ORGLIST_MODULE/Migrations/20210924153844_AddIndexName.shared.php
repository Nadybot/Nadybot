<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class AddIndexName implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "organizations";
		$db->table($table)->truncate();
		$db->schema()->table($table, function(Blueprint $table) {
			$table->string("index", 6)->index();
		});
	}
}
