<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\PVP_MODULE\NotumWarsController;

class AddAttackOver implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = NotumWarsController::DB_ATTACKS;
		$db->schema()->table($table, function (Blueprint $table) {
			$table->integer("penalizing_ended")->nullable(true)->index();
		});
		$query = $db->table($table);
		$query
			->where("timestamp", "<", time() - 6 * 3600)
			->update([
				"penalizing_ended" => $query->raw("timestamp + " . 3600 * 6),
			]);
	}
}
