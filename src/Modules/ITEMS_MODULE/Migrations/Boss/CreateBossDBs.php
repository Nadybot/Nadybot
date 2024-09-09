<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Migrations\Boss;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\ITEMS_MODULE\{BossLootdb, BossNamedb};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_26_16_45_45, shared: true)]
class CreateBossDBs implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->schema()->dropIfExists(BossLootdb::getTable());
		$db->schema()->create(BossLootdb::getTable(), static function (Blueprint $table): void {
			$table->integer('bossid')->index();
			$table->string('itemname', 100);
			$table->integer('aoid')->nullable();
		});

		$db->schema()->dropIfExists(BossNamedb::getTable());
		$db->schema()->create(BossNamedb::getTable(), static function (Blueprint $table): void {
			$table->integer('bossid')->primary();
			$table->string('bossname', 50)->index();
		});
	}
}
