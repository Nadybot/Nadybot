<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Migrations\Items;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\ITEMS_MODULE\{AODBEntry, ItemGroup, ItemGroupName};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_01_08_11_27_14, shared: true)]
class CreateItemDBs implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->schema()->dropIfExists(AODBEntry::getTable());
		$db->schema()->create(AODBEntry::getTable(), static function (Blueprint $table): void {
			$table->integer('lowid')->nullable(false)->index();
			$table->integer('highid')->nullable(false)->index();
			$table->integer('lowql')->nullable(false)->index();
			$table->integer('highql')->nullable(false)->index();
			$table->string('name', 150)->nullable(false)->index();
			$table->integer('icon')->nullable(false);
			$table->boolean('froob_friendly')->nullable(false)->index();
			$table->integer('slot')->nullable(false);
			$table->integer('flags')->nullable(false);
			$table->boolean('in_game')->nullable(false);
		});

		$db->schema()->dropIfExists(ItemGroup::getTable());
		$db->schema()->create(ItemGroup::getTable(), static function (Blueprint $table): void {
			$table->integer('id')->primary();
			$table->integer('group_id')->index();
			$table->integer('item_id')->index();
		});

		$db->schema()->dropIfExists(ItemGroupName::getTable());
		$db->schema()->create(ItemGroupName::getTable(), static function (Blueprint $table): void {
			$table->integer('group_id')->primary();
			$table->string('name', 150);
		});
	}
}
