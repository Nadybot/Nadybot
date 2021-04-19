<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\DEV_MODULE\SilenceController;

class CreateSilenceCmdTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = SilenceController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->string("cmd", 25);
			$table->string("channel", 18);
		});
	}
}
