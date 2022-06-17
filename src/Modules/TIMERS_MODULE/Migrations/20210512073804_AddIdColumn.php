<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\TIMERS_MODULE\TimerController;
use stdClass;

class AddIdColumn implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = TimerController::DB_TABLE;
		$data = $db->schema()->hasTable($table) ? $db->table($table)->get() : new Collection();
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
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
		$data->each(function (stdClass $timer) use ($db, $table): void {
			$db->table($table)->insert([
				"name" => (string)$timer->name,
				"owner" => (string)$timer->owner,
				"mode" => (string)$timer->mode,
				"endtime" => isset($timer->endtime) ? (int)$timer->endtime : null,
				"settime" => (int)$timer->settime,
				"callback" => (string)$timer->callback,
				"data" => isset($timer->data) ? (string)$timer->data : null,
				"alerts" => (string)$timer->alerts,
			]);
		});
	}
}
