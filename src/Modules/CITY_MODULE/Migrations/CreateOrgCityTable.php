<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\CITY_MODULE\CloakController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_25_13_47_33)]
class CreateOrgCityTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = CloakController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('time')->nullable();
			$table->string('action', 10)->nullable();
			$table->string('player', 25)->nullable();
		});
	}
}
