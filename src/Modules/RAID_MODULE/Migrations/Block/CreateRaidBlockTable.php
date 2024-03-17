<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Block;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidBlockController;
use Psr\Log\LoggerInterface;

#[NCA\MigrationOrder(20210427093120)]
class CreateRaidBlockTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RaidBlockController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("player", 15)->index();
			$table->string("blocked_from", 20);
			$table->string("blocked_by", 15);
			$table->text("reason");
			$table->integer("time");
			$table->integer("expiration")->nullable()->index();
		});
	}
}
