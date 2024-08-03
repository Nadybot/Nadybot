<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\{Route, RouteModifier, RouteModifierArgument};
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_11_05_00)]
class MigrateRouteTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$this->migrateRouteTable($logger, $db);
		$this->migrateRouteModifierTable($logger, $db);
		$this->migrateRouteModifierArgumentTable($logger, $db);
	}

	public function migrateRouteTable(LoggerInterface $logger, DB $db): void {
		$idMapping = $db->migrateIdToUuid(
			Route::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('source', 100);
				$table->string('destination', 100);
				$table->boolean('two_way')->default(false);
				$table->unsignedInteger('disabled_until')->nullable(true);
			}
		);

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

	public function migrateRouteModifierTable(LoggerInterface $logger, DB $db): void {
		$idMapping = $db->migrateIdToUuid(
			RouteModifier::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->uuid('route_id')->index();
				$table->string('modifier', 100);
			},
		);

		$table = RouteModifierArgument::getTable();
		$entries = $db->table($table)->get();
		$db->table($table)->truncate();
		$db->schema()->dropColumns($table, 'route_modifier_id');
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->uuid('route_modifier_id')->nullable(false)->index();
		});

		/** @return array<string,mixed> */
		$entries = $entries->map(static function (\stdClass $entry) use ($idMapping): array {
			$entry->route_modifier_id = $idMapping[(int)$entry->route_modifier_id];
			return (array)$entry;
		})->toList();
		$db->table($table)->chunkInsert($entries);
	}

	public function migrateRouteModifierArgumentTable(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			RouteModifierArgument::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->uuid('route_modifier_id')->index();
				$table->string('name', 100);
				$table->string('value', 200);
			}
		);
	}
}
