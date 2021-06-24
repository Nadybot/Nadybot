<?php declare(strict_types=1);

namespace Nadybot\Modules\BROADCAST_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\BROADCAST_MODULE\BroadcastController;

class CreateBroadcastTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = BroadcastController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->string("name", 255)->nullable();
			$table->string("added_by", 25)->nullable();
			$table->integer("dt")->nullable();
		});
	}
}
