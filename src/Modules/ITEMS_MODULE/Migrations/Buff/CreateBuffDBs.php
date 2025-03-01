<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Migrations\Buff;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\ITEMS_MODULE\{Buff, ItemBuff};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_26_16_47_51, shared: true)]
class CreateBuffDBs implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->schema()->dropIfExists(ItemBuff::getTable());
		$db->schema()->create(ItemBuff::getTable(), static function (Blueprint $table): void {
			$table->integer('item_id')->index();
			$table->integer('attribute_id')->index();
			$table->integer('amount');
		});

		$db->schema()->dropIfExists(Buff::getTable());
		$db->schema()->create(Buff::getTable(), static function (Blueprint $table): void {
			$table->integer('id')->primary();
			$table->integer('nano_id')->nullable()->index();
			$table->integer('disc_id')->nullable();
			$table->integer('use_id')->nullable();
			$table->string('name', 255)->nullable();
			$table->integer('ncu')->nullable();
			$table->integer('nanocost')->nullable();
			$table->integer('school')->nullable();
			$table->integer('strain')->nullable();
			$table->integer('duration')->nullable();
			$table->integer('attack')->nullable();
			$table->integer('recharge')->nullable();
			$table->integer('range')->nullable();
			$table->integer('initskill')->nullable();
			$table->boolean('froob_friendly')->nullable();
		});
	}
}
