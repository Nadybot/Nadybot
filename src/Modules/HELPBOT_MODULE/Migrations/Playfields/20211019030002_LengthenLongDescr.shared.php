<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE\Migrations\Playfields;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class LengthenLongDescr implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "playfields";
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->string("long_name", 30)->change();
		});
	}
}
