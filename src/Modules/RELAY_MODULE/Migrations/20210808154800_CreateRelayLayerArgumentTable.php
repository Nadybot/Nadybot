<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\RELAY_MODULE\RelayController;

class CreateRelayLayerArgumentTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = RelayController::DB_TABLE_ARGUMENT;
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->unsignedBigInteger("layer_id")->index();
			$table->string("name", 100);
			$table->string("value", 200);
		});
	}
}
