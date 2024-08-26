<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RELAY_MODULE\RelayConfig;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_08_08_15_43_07)]
class CreateRelayTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RelayConfig::getTable();
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->string('name', 100)->unique();
		});
	}
}
