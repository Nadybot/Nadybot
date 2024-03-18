<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE\Migrations\Weapons;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20211207060105)]
class IndexAlienWeaponTables implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
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