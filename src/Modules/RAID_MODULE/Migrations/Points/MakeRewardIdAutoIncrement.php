<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Points;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidPointsController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_505_103_103)]
class MakeRewardIdAutoIncrement implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RaidPointsController::DB_TABLE_REWARD;
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->id('id')->change();
		});
	}
}
