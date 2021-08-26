<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE\Migrations\Arbiter;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\HELPBOT_MODULE\ArbiterController;

class MigrateArbiterToDB implements SchemaMigration {
	/** @Inject */
	public ArbiterController $arbiterController;

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = ArbiterController::DB_TABLE;
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->string("type", 3)->unique();
			$table->unsignedInteger("start")->index();
			$table->unsignedInteger("end")->index();
		});
		$db->table($table)->insert([
			["type" => ArbiterController::AI,  "start" => 1618704000, "end" => 1619395200],
			["type" => ArbiterController::BS,  "start" => 1619913600, "end" => 1620604800],
			["type" => ArbiterController::DIO, "start" => 1621123200, "end" => 1621814400],
		]);
	}
}
