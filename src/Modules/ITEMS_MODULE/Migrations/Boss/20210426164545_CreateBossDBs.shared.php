<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Migrations\Boss;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateBossDBs implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$db->schema()->dropIfExists("boss_lootdb");
		$db->schema()->create("boss_lootdb", function(Blueprint $table) {
			$table->integer("bossid")->index();
			$table->string("itemname", 100);
			$table->integer("aoid")->nullable();
		});

		$db->schema()->dropIfExists("boss_namedb");
		$db->schema()->create("boss_namedb", function(Blueprint $table) {
			$table->integer("bossid")->primary();
			$table->string("bossname", 50)->index();
		});
	}
}
