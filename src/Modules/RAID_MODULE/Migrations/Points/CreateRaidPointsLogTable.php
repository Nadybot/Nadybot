<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Points;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{Collection, DB, Types\SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidPointsLog;
use Psr\Log\LoggerInterface;
use stdClass;

#[NCA\Migration(order: 2021_04_27_10_24_54)]
class CreateRaidPointsLogTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RaidPointsLog::getTable();
		if ($db->schema()->hasTable($table)) {
			if ($db->schema()->hasColumn($table, 'individual')) {
				return;
			}
			$db->schema()->table($table, static function (Blueprint $table): void {
				$table->boolean('individual')->default(true)->index()->change();
			});

			/** @var Collection<int,stdClass> */
			$data = $db->table($table)->get();
			$data->each(static function (stdClass $log) use ($db, $table): void {
				$db->table($table)
					->where('time', (int)$log->time)
					->where('username', (string)$log->username)
					->where('changed_by', (string)$log->changed_by)
					->where('delta', (int)$log->delta)
					->where('reason', (string)$log->reason)
					->where('ticker', (int)$log->ticker)
					->update([
						'individual' => !$log->ticker && !in_array((string)$log->reason, ['reward', 'penalty'], true),
					]);
			});
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('username', 20)->index();
			$table->integer('delta');
			$table->integer('time')->index();
			$table->string('changed_by', 20)->index();
			$table->boolean('individual')->default(true)->index();
			$table->text('reason');
			$table->boolean('ticker')->default(false)->index();
			$table->integer('raid_id')->nullable()->index();
		});
	}
}
