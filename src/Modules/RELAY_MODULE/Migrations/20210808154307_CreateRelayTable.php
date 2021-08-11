<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\RELAY_MODULE\RelayController;

class CreateRelayTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = RelayController::DB_TABLE;
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->string("name", 100)->unique();
		});
	}
}
