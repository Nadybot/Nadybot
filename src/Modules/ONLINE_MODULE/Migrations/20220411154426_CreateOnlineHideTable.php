<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\ONLINE_MODULE\OnlineController;

class CreateOnlineHideTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = OnlineController::DB_TABLE_HIDE;
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->string("mask", 20)->unique();
			$table->string("created_by", 12);
			$table->unsignedInteger("created_on");
		});
	}
}
