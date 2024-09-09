<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\RELAY_MODULE\RelayEvent;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_10_26_19_42_13)]
class AddRelayEvents implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RelayEvent::getTable();
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->unsignedBigInteger('relay_id')->index();
			$table->string('event', 50);
			$table->boolean('incoming')->default(false);
			$table->boolean('outgoing')->default(false);
			$table->unique(['relay_id', 'event']);
		});
	}
}
