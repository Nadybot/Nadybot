<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	Types\SchemaMigration,
};
use Nadybot\Modules\TRACKER_MODULE\Tracking;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Safe\DateTimeImmutable;

#[NCA\Migration(order: 2025_03_07_14_19_00)]
class AddUuidsToTracking implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Tracking::getTable();
		$entries = $db->table($table)->orderBy('dt')->get();
		$db->schema()->drop($table);
		$db->schema()->create(
			$table,
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->bigInteger('uid');
				$table->integer('dt');
				$table->string('event', 6);
			}
		);

		/** @return array<string,mixed> */
		$converter = static function (\stdClass $entry): array {
			$time = (new DateTimeImmutable())->setTimestamp((int)$entry->dt);
			$uuid = Uuid::uuid7($time);
			$entry->id = $uuid->toString();
			return (array)$entry;
		};

		$entries = $entries->map($converter)->toList();
		$db->table($table)->chunkInsert($entries);
	}
}
