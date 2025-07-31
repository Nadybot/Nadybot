<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\NEWS_MODULE\{News, NewsConfirmed};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[NCA\Migration(order: 2024_08_02_14_08_00, shared: true)]
class MigrateNewsTableToUuid implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$column = 'id';
		$table = News::getTable();

		/** @var Collection<int,object{id:int,time?:int,uuid?:string}&\stdClass> */
		$entries = $db->table($table)->orderBy($column)->get();
		$db->schema()->drop($table);
		$db->schema()->create(
			$table,
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->integer('time');
				$table->string('name', 30)->nullable();
				$table->text('news')->nullable();
				$table->tinyInteger('sticky');
				$table->tinyInteger('deleted');
			},
		);

		/** @var array<int,UuidInterface> */
		$idToUuid = [];

		/**
		 * @param object{id:int,time?:int,uuid?:string}&\stdClass $entry
		 *
		 * @return array<string,mixed>
		 */
		$entries = $entries->map(static function (object $entry) use (&$idToUuid): array {
			$time = $entry->time ?? null;
			if (isset($time)) {
				$time = (new DateTimeImmutable())->setTimestamp($time);
			}
			$uuid = isset($entry->uuid) ? Uuid::fromString($entry->uuid) : Uuid::uuid7($time);
			$idToUuid[$entry->id] = $uuid;
			$entry->id = $uuid->toString();
			$array = (array)$entry;
			unset($array['uuid']);
			return $array;
		})->toList();
		$db->table($table)->chunkInsert($entries);

		$table = NewsConfirmed::getTable();

		/** @var Collection<int,object{id:int}&\stdClass> */
		$confirmed = $db->table($table)->get();
		$db->schema()->drop($table);
		$db->schema()->create(
			$table,
			static function (Blueprint $table): void {
				$table->uuid('id')->index();
				$table->string('player', 20)->index();
				$table->integer('time');
				$table->unique(['id', 'player']);
			}
		);

		/**
		 * @param object{id:int}&\stdClass $entry
		 *
		 * @return array<string,mixed>
		 */
		$entries = $confirmed->map(static function (object $entry) use ($idToUuid): array {
			$entry->id = $idToUuid[$entry->id];
			return (array)$entry;
		})->toList();
		$db->table($table)->chunkInsert($entries);
	}
}
