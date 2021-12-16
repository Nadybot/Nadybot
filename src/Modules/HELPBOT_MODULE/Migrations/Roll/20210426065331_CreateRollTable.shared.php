<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE\Migrations\Roll;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateRollTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "roll";
		if ($db->schema()->hasTable("roll")) {
			$db->schema()->table($table, function(Blueprint $table): void {
				$table->id("id")->change();
				$table->text("options")->change();
			});
			return;
		}
		$db->schema()->create($table, function(Blueprint $table): void {
			$table->id();
			$table->integer("time")->nullable();
			$table->string("name", 255)->nullable();
			$table->text("options")->nullable();
			$table->string("result", 255)->nullable();
		});
	}
}
