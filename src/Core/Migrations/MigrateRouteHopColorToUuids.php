<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\RouteHopColor;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_15_35_00)]
class MigrateRouteHopColorToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			RouteHopColor::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('hop', 50)->default('*');
				$table->string('where', 50)->nullable(true);
				$table->string('via', 50)->nullable(true);
				$table->string('tag_color', 6)->nullable(true);
				$table->string('text_color', 6)->nullable(true);
				$table->unique(['hop', 'where', 'via']);
			}
		);
	}
}
