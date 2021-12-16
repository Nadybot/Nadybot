<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Modules\BAN\BanController;
use Nadybot\Core\SchemaMigration;

class CreateBanlistTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = BanController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function(Blueprint $table): void {
			$table->bigInteger("charid")->primary();
			$table->string("admin", 25)->nullable();
			$table->integer("time")->nullable();
			$table->text("reason")->nullable();
			$table->integer("banend")->nullable()->index();
		});
	}
}
