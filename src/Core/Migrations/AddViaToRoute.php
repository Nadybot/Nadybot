<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\{RouteHopColor, RouteHopFormat};
use Nadybot\Core\{DB, Types\SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_10_06_14_15_48)]
class AddViaToRoute implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RouteHopColor::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->string('via', 50)->nullable(true);
			$table->dropUnique(['hop', 'where']);
			$table->unique(['hop', 'where', 'via']);
		});

		$table = RouteHopFormat::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->string('via', 50)->nullable(true);
			$table->dropUnique(['hop', 'where']);
			$table->unique(['hop', 'where', 'via']);
		});
	}
}
