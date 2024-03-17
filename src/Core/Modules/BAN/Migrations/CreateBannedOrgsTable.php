<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Modules\BAN\BanController;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\MigrationOrder(20210622064701)]
class CreateBannedOrgsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = BanController::DB_TABLE_BANNED_ORGS;
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->bigInteger("org_id")->primary();
			$table->string("banned_by", 15);
			$table->integer("start");
			$table->integer("end")->nullable()->index();
			$table->text("reason")->nullable();
		});
	}
}
