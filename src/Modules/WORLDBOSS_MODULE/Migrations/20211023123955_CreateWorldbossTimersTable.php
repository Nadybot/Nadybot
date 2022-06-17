<?php declare(strict_types=1);

namespace Nadybot\User\Modules\BIGBOSS_MODULE\Migrations\Worldboss;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{CommandManager, DB, EventManager, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\WORLDBOSS_MODULE\WorldBossController;
use stdClass;

class CreateWorldbossTimersTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = WorldBossController::DB_TABLE;
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("mob_name", 50)->primary();
			$table->integer("timer");
			$table->integer("spawn");
			$table->integer("killable");
			$table->integer("time_submitted");
			$table->string("submitter_name", 25);
		});
		if (!$db->schema()->hasTable("bigboss_timers")) {
			return;
		}
		$this->migrateBigbossData($logger, $db);
	}

	protected function migrateBigbossData(LoggerWrapper $logger, DB $db): void {
		$db->table("bigboss_timers")
			->get()
			->each(function (stdClass $timer) use ($db): void {
				$db->table(WorldBossController::DB_TABLE)->insert([
					"mob_name" => (string)$timer->mob_name,
					"timer" => (int)$timer->timer,
					"spawn" => (int)$timer->spawn,
					"killable" => (int)$timer->killable,
					"time_submitted" => (int)$timer->time_submitted,
					"submitter_name" => (string)$timer->submitter_name,
				]);
			});
		$db->table(CommandManager::DB_TABLE)
			->where('module', 'BIGBOSS_MODULE')
			->update(["status" => 0]);
		$db->table(EventManager::DB_TABLE)
			->where('module', 'BIGBOSS_MODULE')
			->update(["status" => 0]);
	}
}
