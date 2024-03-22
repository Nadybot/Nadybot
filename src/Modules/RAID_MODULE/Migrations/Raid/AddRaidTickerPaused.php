<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Raid;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_231_002_193_036)]
class AddRaidTickerPaused implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RaidController::DB_TABLE;
		$db->schema()->table($table, static function (Blueprint $table) {
			$table->boolean('ticker_paused')->default(false);
		});
	}
}
