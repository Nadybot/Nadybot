<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Points;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidReward;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_27_13_12_55)]
class CreateRaidRewardTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RaidReward::getTable();
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, static function (Blueprint $table): void {
				$table->integer('id')->change();
			});
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('id')->primary();
			$table->string('name', 20)->index();
			$table->integer('points');
			$table->string('reason', 100);
		});
	}
}
