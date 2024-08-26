<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Block;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidBlock;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_04_11_08_34_20)]
class AddRaidBlockTableUnique implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RaidBlock::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->unique('player', 'blocked_from');
		});
	}
}
