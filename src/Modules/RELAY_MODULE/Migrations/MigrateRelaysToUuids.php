<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	SchemaMigration,
};
use Nadybot\Modules\RELAY_MODULE\{
	RelayConfig,
	RelayEvent,
	RelayLayer,
	RelayLayerArgument,
	RelayProperty,
};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\Migration(order: 2024_08_05_10_09_00)]
class MigrateRelaysToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$relayIdToUuid = $this->migrateRelayTable($logger, $db);
		$layerIdToUuid = $this->migrateRelayLayerTable($logger, $db, $relayIdToUuid);
		$this->migrateRelayLayerArgumentTable($logger, $db, $layerIdToUuid);
		$this->migrateRelayEventTable($logger, $db, $relayIdToUuid);
		$this->migrateRelayPropertiesTable($logger, $db, $relayIdToUuid);
	}

	/** @return array<int,UuidInterface> */
	private function migrateRelayTable(LoggerInterface $logger, DB $db): array {
		$createTable = static function (Blueprint $table): void {
			$table->uuid('id')->primary();
			$table->string('name', 100)->unique();
		};
		return $db->migrateIdToUuid(RelayConfig::getTable(), $createTable);
	}

	/**
	 * @param array<int,UuidInterface> $relayIdToUuid
	 *
	 * @return array<int,UuidInterface>
	 */
	private function migrateRelayLayerTable(
		LoggerInterface $logger,
		DB $db,
		array $relayIdToUuid
	): array {
		$table = RelayLayer::getTable();
		$createTable = static function (Blueprint $table): void {
			$table->uuid('id')->primary();
			$table->uuid('relay_id')->index();
			$table->string('layer', 100);
		};
		$entries = $db->table($table)->orderBy('id')->get();
		$db->schema()->drop($table);
		$db->schema()->create($table, $createTable);

		$result = [];

		/** @return array<string,mixed> */
		$entries = $entries->map(static function (\stdClass $entry) use (&$result, $relayIdToUuid): array {
			$uuid = Uuid::uuid7();
			$result[(int)$entry->id] = $uuid;
			$entry->id = $uuid->toString();
			$entry->relay_id = $relayIdToUuid[$entry->relay_id]->toString();
			return (array)$entry;
		})->toList();
		$db->table($table)->chunkInsert($entries);
		return $result;
	}

	/** @param array<int,UuidInterface> $relayLayerIdToUuid */
	private function migrateRelayLayerArgumentTable(
		LoggerInterface $logger,
		DB $db,
		array $relayLayerIdToUuid
	): void {
		$table = RelayLayerArgument::getTable();
		$createTable = static function (Blueprint $table): void {
			$table->uuid('id');
			$table->uuid('layer_id')->index();
			$table->string('name', 100);
			$table->string('value', 200);
		};
		$entries = $db->table($table)->orderBy('id')->get();
		$db->schema()->drop($table);
		$db->schema()->create($table, $createTable);

		$result = [];

		/** @return array<string,mixed> */
		$entries = $entries->map(static function (\stdClass $entry) use (&$result, $relayLayerIdToUuid): array {
			$uuid = Uuid::uuid7();
			$result[(int)$entry->id] = $uuid;
			$entry->id = $uuid->toString();
			$entry->layer_id = $relayLayerIdToUuid[$entry->layer_id]->toString();
			return (array)$entry;
		})->toList();
		$db->table($table)->chunkInsert($entries);
	}

	/** @param array<int,UuidInterface> $relayIdToUuid */
	private function migrateRelayEventTable(
		LoggerInterface $logger,
		DB $db,
		array $relayIdToUuid
	): void {
		$table = RelayEvent::getTable();
		$createTable = static function (Blueprint $table): void {
			$table->uuid('id')->primary();
			$table->uuid('relay_id')->index();
			$table->string('event', 50);
			$table->boolean('incoming')->default(false);
			$table->boolean('outgoing')->default(false);
			$table->unique(['relay_id', 'event']);
		};
		$entries = $db->table($table)->orderBy('id')->get();
		$db->schema()->drop($table);
		$db->schema()->create($table, $createTable);

		/** @return array<string,mixed> */
		$entries = $entries->map(static function (\stdClass $entry) use ($relayIdToUuid): array {
			$uuid = Uuid::uuid7();
			$entry->id = $uuid->toString();
			$entry->relay_id = $relayIdToUuid[$entry->relay_id]->toString();
			return (array)$entry;
		})->toList();
		$db->table($table)->chunkInsert($entries);
	}

	/** @param array<int,UuidInterface> $relayIdToUuid */
	private function migrateRelayPropertiesTable(
		LoggerInterface $logger,
		DB $db,
		array $relayIdToUuid
	): void {
		$table = RelayProperty::getTable();
		$createTable = static function (Blueprint $table): void {
			$table->uuid('relay_id')->nullable(false)->index();
			$table->string('property', 50)->nullable(false)->index();
			$table->string('value')->nullable(true);
			$table->unique(['relay_id', 'property']);
		};
		$entries = $db->table($table)->get();
		$db->schema()->drop($table);
		$db->schema()->create($table, $createTable);

		/** @return array<string,mixed> */
		$entries = $entries->map(static function (\stdClass $entry) use ($relayIdToUuid): array {
			$entry->relay_id = $relayIdToUuid[$entry->relay_id]->toString();
			return (array)$entry;
		})->toList();
		$db->table($table)->chunkInsert($entries);
	}
}
