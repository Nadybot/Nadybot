<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\{Route, RouteModifier};
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_11_05_00)]
class MigrateRouteTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$idMapping = $db->migrateIdToUuid(Route::getTable(), 'id');

		$table = RouteModifier::getTable();
		$entries = $db->table($table)->get();
		$db->table($table)->truncate();
		$db->schema()->dropColumns($table, 'route_id');
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->uuid('route_id')->nullable(false)->index();
		});

		/** @return array<string,mixed> */
		$entries = $entries->map(static function (\stdClass $entry) use ($idMapping): array {
			$entry->route_id = $idMapping[(int)$entry->route_id];
			return (array)$entry;
		})->toList();
		$db->table($table)->chunkInsert($entries);
	}
}
