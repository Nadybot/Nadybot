<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RELAY_MODULE\RelayController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_808_154_307)]
class CreateRelayTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RelayController::DB_TABLE;
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->string('name', 100)->unique();
		});
	}
}
