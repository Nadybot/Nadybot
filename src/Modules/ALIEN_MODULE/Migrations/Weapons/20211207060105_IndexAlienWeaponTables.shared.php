<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE\Migrations\Weapons;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class IndexAlienWeaponTables implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "alienweapons";
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->integer("type")->index()->change();
		});

		$table = "alienweaponspecials";
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->integer("type")->index()->change();
		});
	}
}
