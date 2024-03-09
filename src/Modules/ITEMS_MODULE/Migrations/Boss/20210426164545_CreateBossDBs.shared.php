<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Migrations\Boss;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

class CreateBossDBs implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->schema()->dropIfExists("boss_lootdb");
		$db->schema()->create("boss_lootdb", function (Blueprint $table): void {
			$table->integer("bossid")->index();
			$table->string("itemname", 100);
			$table->integer("aoid")->nullable();
		});

		$db->schema()->dropIfExists("boss_namedb");
		$db->schema()->create("boss_namedb", function (Blueprint $table): void {
			$table->integer("bossid")->primary();
			$table->string("bossname", 50)->index();
		});
	}
}
