<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RELAY_MODULE\RelayController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210808154800)]
class CreateRelayLayerArgumentTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RelayController::DB_TABLE_ARGUMENT;
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->unsignedBigInteger("layer_id")->index();
			$table->string("name", 100);
			$table->string("value", 200);
		});
	}
}
