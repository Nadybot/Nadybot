<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\RELAY_MODULE\RelayLayerArgument;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_08_08_15_48_00)]
class CreateRelayLayerArgumentTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RelayLayerArgument::getTable();
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->unsignedBigInteger('layer_id')->index();
			$table->string('name', 100);
			$table->string('value', 200);
		});
	}
}
