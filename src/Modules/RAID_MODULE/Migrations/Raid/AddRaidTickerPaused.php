<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Raid;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20231002193036)]
class AddRaidTickerPaused implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RaidController::DB_TABLE;
		$db->schema()->table($table, function (Blueprint $table) {
			$table->boolean("ticker_paused")->default(false);
		});
	}
}
