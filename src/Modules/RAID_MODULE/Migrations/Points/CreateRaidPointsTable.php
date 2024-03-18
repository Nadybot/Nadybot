<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Points;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidPointsController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210427102450)]
class CreateRaidPointsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RaidPointsController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("username", 20)->primary();
			$table->integer("points");
		});
	}
}
