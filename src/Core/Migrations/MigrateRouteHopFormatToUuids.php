<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\RouteHopFormat;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_15_55_00)]
class MigrateRouteHopFormatToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			RouteHopFormat::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('hop', 50);
				$table->string('where', 50)->nullable(true);
				$table->string('via', 50)->nullable(true);
				$table->boolean('render')->default(true);
				$table->string('format', 50)->default('%s');
				$table->unique(['hop', 'where', 'via']);
			},
			'id'
		);
	}
}
