<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\RELAY_MODULE\RelayController;

class AddRelayEvents implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = RelayController::DB_TABLE_EVENT;
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->unsignedBigInteger("relay_id")->index();
			$table->string("event", 50);
			$table->boolean("incoming")->default(false);
			$table->boolean("outgoing")->default(false);
			$table->unique(["relay_id", "event"]);
		});
	}
}
