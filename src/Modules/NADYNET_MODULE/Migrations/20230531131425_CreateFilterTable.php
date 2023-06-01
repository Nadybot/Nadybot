<?php declare(strict_types=1);

namespace Nadybot\Modules\NADYNET_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\NADYNET_MODULE\NadynetController;

class CreateFilterTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = NadynetController::DB_TABLE;
		$db->schema()->create($table, function (Blueprint $table) {
			$table->id();
			$table->string("creator", 12)->nullable(false);
			$table->string("sender_name", 12)->nullable(true);
			$table->unsignedInteger("sender_uid")->nullable(true);
			$table->string("bot_name", 12)->nullable(true);
			$table->unsignedInteger("bot_uid")->nullable(true);
			$table->string("channel", 25)->nullable(true);
			$table->unsignedSmallInteger("dimension")->nullable(true);
			$table->unsignedInteger("expires")->nullable(true);
		});
	}
}
