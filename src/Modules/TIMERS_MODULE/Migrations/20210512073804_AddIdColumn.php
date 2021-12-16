<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\TIMERS_MODULE\TimerController;

class AddIdColumn implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = TimerController::DB_TABLE;
		$data = $db->table($table)
			->asObj()
			->map(function(object $item) {
				return get_object_vars($item);
			})->toArray();
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function(Blueprint $table): void {
			$table->id();
			$table->string("name", 255)->unique();
			$table->string("owner", 25);
			$table->string("mode", 50);
			$table->integer("endtime")->nullable();
			$table->integer("settime");
			$table->string("callback", 255);
			$table->string("data", 255)->nullable();
			$table->text("alerts");
		});
		$db->table($table)->chunkInsert($data);
	}
}
