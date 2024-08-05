<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\VOTE_MODULE\{Poll, Vote};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;

#[NCA\Migration(order: 2024_08_05_16_42_00)]
class MigratePollsTouuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$idToUuid = $this->migratePolls($logger, $db);
		$this->migrateVotes($logger, $db, $idToUuid);
	}

	/** @return array<int,UuidInterface> */
	private function migratePolls(LoggerInterface $logger, DB $db): array {
		$createTable = static function (Blueprint $table): void {
			$table->uuid('id')->primary();
			$table->string('author', 20);
			$table->text('question');
			$table->text('possible_answers');
			$table->integer('started');
			$table->integer('duration');
			$table->integer('status');
			$table->boolean('allow_other_answers')->default(true);
		};
		$idToUuid = $db->migrateIdToUuid(Poll::getTable(), $createTable, 'id', 'started');
		return $idToUuid;
	}

	/** @param array<int,UuidInterface> $idToUuid */
	private function migrateVotes(LoggerInterface $logger, DB $db, array $idToUuid): void {
		$createTable = static function (Blueprint $table): void {
			$table->uuid('poll_id')->index();
			$table->string('author', 20);
			$table->text('answer')->nullable();
			$table->integer('time')->nullable();
			$table->unique(['poll_id', 'author']);
		};
		$table = Vote::getTable();
		$entries = $db->table($table)->get();
		$db->schema()->drop($table);
		$db->schema()->create($table, $createTable);

		/** @return array<string,mixed> */
		$entries = $entries->map(static function (\stdClass $entry) use ($idToUuid): array {
			$entry->poll_id = $idToUuid[(int)$entry->poll_id]->toString();
			return (array)$entry;
		})->toList();
		$db->table($table)->chunkInsert($entries);
	}
}
