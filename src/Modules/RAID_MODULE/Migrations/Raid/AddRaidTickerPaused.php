<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Raid;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\Raid;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_10_02_19_30_36)]
class AddRaidTickerPaused implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Raid::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->boolean('ticker_paused')->default(false);
		});
	}
}
