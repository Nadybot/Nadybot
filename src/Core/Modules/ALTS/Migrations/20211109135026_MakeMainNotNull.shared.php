<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class MakeMainNotNull implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "alts";
		$db->schema()->table($table, function(Blueprint $table): void {
			$table->string("main", 25)->nullable(false)->change();
		});
	}
}
