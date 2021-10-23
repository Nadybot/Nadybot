<?php declare(strict_types=1);

namespace Nadybot\User\Modules\BIGBOSS_MODULE\Migrations\Worldboss;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\CommandManager;
use Nadybot\Core\DB;
use Nadybot\Core\EventManager;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\WORLDBOSS_MODULE\WorldBossController;

class CreateWorldbossTimersTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = WorldBossController::DB_TABLE;
		$db->schema()->create($table, function(Blueprint $table) {
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
		$oldTimers = $db->table("bigboss_timers")
			->asObj()
			->map(function(object $o): array {
				return (array)$o;
			})
			->toArray();
		$db->table(WorldBossController::DB_TABLE)
			->insert($oldTimers);
		// $db->schema()->dropIfExists("bigboss_timers");
		$db->table(CommandManager::DB_TABLE)
			->where('module', 'BIGBOSS_MODULE')
			->update(["status" => 0]);
		$db->table(EventManager::DB_TABLE)
			->where('module', 'BIGBOSS_MODULE')
			->update(["status" => 0]);
	}
}
