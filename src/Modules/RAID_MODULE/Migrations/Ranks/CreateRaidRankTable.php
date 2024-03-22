<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Ranks;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidRankController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_506_074_534)]
class CreateRaidRankTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RaidRankController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('name', 25)->primary();
			$table->integer('rank');
		});
	}
}
