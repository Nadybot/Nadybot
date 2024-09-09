<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Raid;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidLog;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_10_02_19_35_22)]
class AddRaidLogTickerPaused implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RaidLog::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->boolean('ticker_paused')->default(false);
		});
	}
}
