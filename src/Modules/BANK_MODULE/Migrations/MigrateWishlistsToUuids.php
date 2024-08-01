<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\BANK_MODULE\{Wish, WishFulfilment};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_16_54_21, shared: true)]
class MigrateWishlistsToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$idMapping = $db->migrateIdToUuid(
			Wish::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->unsignedInteger('created_on');
				$table->unsignedInteger('expires_on')->nullable(true);
				$table->string('created_by', 12)->index();
				$table->string('item', 200);
				$table->unsignedInteger('amount')->default(1);
				$table->string('from', 12)->nullable(true)->index();
				$table->boolean('fulfilled')->default(false)->index();
			}
		);

		$table = WishFulfilment::getTable();
		$entries = $db->table($table)->get();
		$db->table($table)->truncate();
		$db->schema()->dropColumns($table, 'wish_id');
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->uuid('wish_id')->index();
		});

		/** @return array<string,mixed> */
		$entries = $entries->map(static function (\stdClass $entry) use ($idMapping): array {
			$entry->wish_id = $idMapping[(int)$entry->wish_id];
			return (array)$entry;
		})->toList();
		$db->table($table)->chunkInsert($entries);

		$db->migrateIdToUuid(
			WishFulfilment::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->uuid('wish_id')->index();
				$table->unsignedInteger('amount')->default(1);
				$table->unsignedInteger('fulfilled_on');
				$table->string('fulfilled_by', 12);
			},
			'id',
			'fulfilled_on',
		);
	}
}
