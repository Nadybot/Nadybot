<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\{CmdCfg, EventCfg};
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\WORLDBOSS_MODULE\WorldBossTimer;
use Psr\Log\LoggerInterface;
use stdClass;

#[NCA\Migration(order: 2021_10_23_12_39_55)]
class CreateWorldbossTimersTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = WorldBossTimer::getTable();
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('mob_name', 50)->primary();
			$table->integer('timer');
			$table->integer('spawn');
			$table->integer('killable');
			$table->integer('time_submitted');
			$table->string('submitter_name', 25);
		});
		if (!$db->schema()->hasTable('bigboss_timers')) {
			return;
		}
		$this->migrateBigbossData($logger, $db);
	}

	protected function migrateBigbossData(LoggerInterface $logger, DB $db): void {
		$db->table('bigboss_timers')
			->get()
			->each(static function (stdClass $timer) use ($db): void {
				$db->insert(new WorldBossTimer(
					mob_name: (string)$timer->mob_name,
					timer: (int)$timer->timer,
					spawn: (int)$timer->spawn,
					killable: (int)$timer->killable,
					time_submitted: (int)$timer->time_submitted,
					submitter_name: (string)$timer->submitter_name,
				));
			});
		$db->table(CmdCfg::getTable())
			->where('module', 'BIGBOSS_MODULE')
			->update(['status' => 0]);
		$db->table(EventCfg::getTable())
			->where('module', 'BIGBOSS_MODULE')
			->update(['status' => 0]);
	}
}
