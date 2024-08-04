<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Raid;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\{DBAuction, Raid, RaidLog, RaidMember, RaidPointsLog};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[NCA\Migration(order: 2024_08_04_18_24_00)]
class MigrateRaidTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$idToUuid = $this->migrateRaidTable($logger, $db);
		$this->migrateRaidLogs($logger, $db, $idToUuid);
		$this->migrateAuctions($logger, $db, $idToUuid);
		$this->migrateRaidMembers($logger, $db, $idToUuid);
		$this->migrateRaidPointLogs($logger, $db, $idToUuid);
	}

	/** @return array<int,UuidInterface> */
	private function migrateRaidTable(LoggerInterface $logger, DB $db): array {
		return $db->migrateIdToUuid(
			Raid::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('raid_id')->primary();
				$table->string('description', 255)->nullable();
				$table->integer('seconds_per_point');
				$table->integer('announce_interval');
				$table->boolean('locked')->default(false);
				$table->integer('started')->index();
				$table->string('started_by', 20);
				$table->integer('stopped')->nullable()->index();
				$table->string('stopped_by', 20)->nullable();
				$table->unsignedInteger('max_members')->nullable(true);
				$table->boolean('ticker_paused')->default(false);
			},
			'raid_id',
			'started'
		);
	}

	/** @param array<int,UuidInterface> $idToUuid */
	private function migrateRaidLogs(LoggerInterface $logger, DB $db, array $idToUuid): void {
		$raidLogs = $db->table(RaidLog::getTable())->orderBy('time')->get();
		$createRaidLog = static function (Blueprint $table): void {
			$table->uuid('raid_id')->index();
			$table->string('description', 255)->nullable();
			$table->integer('seconds_per_point');
			$table->integer('announce_interval');
			$table->boolean('locked');
			$table->integer('time')->index();
			$table->unsignedInteger('max_members')->nullable(true);
			$table->boolean('ticker_paused')->default(false);
		};

		$db->schema()->drop(RaidLog::getTable());
		$db->schema()->create(RaidLog::getTable(), $createRaidLog);

		/** @return array<string,mixed> */
		$raidLogs = $raidLogs->map(static function (\stdClass $entry) use ($idToUuid): array {
			$entry->raid_id = $idToUuid[$entry->raid_id];
			return (array)$entry;
		})->toList();
		$db->table(RaidLog::getTable())->chunkInsert($raidLogs);
	}

	/** @param array<int,UuidInterface> $idToUuid */
	private function migrateAuctions(LoggerInterface $logger, DB $db, array $idToUuid): void {
		$createAuction = static function (Blueprint $table): void {
			$table->uuid('id')->primary();
			$table->uuid('raid_id')->nullable()->index();
			$table->text('item');
			$table->string('auctioneer', 20);
			$table->integer('cost')->nullable();
			$table->string('winner', 20)->nullable();
			$table->integer('end');
			$table->boolean('reimbursed')->default(false);
		};

		$auctions = $db->table(DBAuction::getTable())->orderBy('id')->get();
		$db->schema()->drop(DBAuction::getTable());
		$db->schema()->create(DBAuction::getTable(), $createAuction);

		/** @return array<string,mixed> */
		$auctions = $auctions->map(static function (\stdClass $entry) use ($idToUuid): array {
			$entry->raid_id = $idToUuid[$entry->raid_id];
			$entry->id = Uuid::uuid7((new DateTimeImmutable())->setTimestamp($entry->end));
			return (array)$entry;
		})->toList();
		$db->table(DBAuction::getTable())->chunkInsert($auctions);
	}

	/** @param array<int,UuidInterface> $idToUuid */
	private function migrateRaidMembers(LoggerInterface $logger, DB $db, array $idToUuid): void {
		$createRaidMembers = static function (Blueprint $table): void {
			$table->uuid('raid_id')->index();
			$table->string('player', 20)->index();
			$table->integer('joined')->nullable();
			$table->integer('left')->nullable();
		};

		$members = $db->table(RaidMember::getTable())->orderBy('raid_id')->get();
		$db->schema()->drop(RaidMember::getTable());
		$db->schema()->create(RaidMember::getTable(), $createRaidMembers);

		/** @return array<string,mixed> */
		$members = $members->map(static function (\stdClass $entry) use ($idToUuid): array {
			$entry->raid_id = $idToUuid[$entry->raid_id];
			return (array)$entry;
		})->toList();
		$db->table(RaidMember::getTable())->chunkInsert($members);
	}

	/** @param array<int,UuidInterface> $idToUuid */
	private function migrateRaidPointLogs(LoggerInterface $logger, DB $db, array $idToUuid): void {
		$createTable =  static function (Blueprint $table): void {
			$table->string('username', 20)->index();
			$table->integer('delta');
			$table->integer('time')->index();
			$table->string('changed_by', 20)->index();
			$table->boolean('individual')->default(true)->index();
			$table->text('reason');
			$table->boolean('ticker')->default(false)->index();
			$table->uuid('raid_id')->nullable()->index();
		};

		$logs = $db->table(RaidPointsLog::getTable())->get();
		$db->schema()->drop(RaidPointsLog::getTable());
		$db->schema()->create(RaidPointsLog::getTable(), $createTable);

		/** @return array<string,mixed> */
		$logs = $logs->map(static function (\stdClass $entry) use ($idToUuid): array {
			if (isset($entry->raid_raid)) {
				$entry->raid_id = $idToUuid[$entry->raid_id] ?? null;
			}
			return (array)$entry;
		})->toList();
		$db->table(RaidPointsLog::getTable())->chunkInsert($logs);
	}
}
