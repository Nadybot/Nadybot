<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Raid;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_27_09_43_32)]
class CreateRaidTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RaidController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, static function (Blueprint $table): void {
				$table->id('raid_id')->change();
			});
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id('raid_id');
			$table->string('description', 255)->nullable();
			$table->integer('seconds_per_point');
			$table->integer('announce_interval');
			$table->boolean('locked')->default(false);
			$table->integer('started')->index();
			$table->string('started_by', 20);
			$table->integer('stopped')->nullable()->index();
			$table->string('stopped_by', 20)->nullable();
		});
	}
}
