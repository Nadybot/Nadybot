<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RELAY_MODULE\RelayProperty;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_05_04_08_06_13)]
class AddRelayProperties implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RelayProperty::getTable();
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->unsignedBigInteger('relay_id')->nullable(false)->index();
			$table->string('property', 50)->nullable(false)->index();
			$table->string('value')->nullable(true);
			$table->unique(['relay_id', 'property']);
		});
	}
}
