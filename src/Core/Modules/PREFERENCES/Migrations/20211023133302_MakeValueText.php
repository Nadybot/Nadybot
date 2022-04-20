<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PREFERENCES\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Modules\PREFERENCES\Preferences;
use Nadybot\Core\SchemaMigration;

class MakeValueText implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = Preferences::DB_TABLE;
		$db->schema()->table($table, function(Blueprint $table): void {
			$table->string("sender", 15)->index()->change();
			$table->string("name", 30)->index()->change();
			$table->text("value")->change();
		});
	}
}
