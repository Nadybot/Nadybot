<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Points;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\{RaidReward};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_05_07_41_00)]
class MigrateRaidRewardTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			RaidReward::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('name', 20)->index();
				$table->integer('points');
				$table->string('reason', 100);
			}
		);
	}
}
