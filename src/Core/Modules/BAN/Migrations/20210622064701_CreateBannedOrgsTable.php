<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Modules\BAN\BanController;
use Nadybot\Core\SchemaMigration;

class CreateBannedOrgsTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = BanController::DB_TABLE_BANNED_ORGS;
		$db->schema()->create($table, function(Blueprint $table): void {
			$table->bigInteger("org_id")->primary();
			$table->string("banned_by", 15);
			$table->integer("start");
			$table->integer("end")->nullable()->index();
			$table->text("reason")->nullable();
		});
	}
}
