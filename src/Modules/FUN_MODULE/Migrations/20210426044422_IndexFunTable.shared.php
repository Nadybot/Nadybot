<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class IndexFunTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "fun";
		$db->schema()->table($table, function(Blueprint $table) {
			$table->string("type", 10)->index()->change();
		});
	}
}
