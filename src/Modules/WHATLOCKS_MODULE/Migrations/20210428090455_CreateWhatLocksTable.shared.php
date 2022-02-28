<?php declare(strict_types=1);

namespace Nadybot\Modules\WHATLOCKS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateWhatLocksTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "what_locks";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function(Blueprint $table): void {
			$table->integer("item_id")->index();
			$table->integer("skill_id")->index();
			$table->integer("duration")->index();
		});
	}
}
