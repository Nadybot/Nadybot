<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE\Migrations\Weapons;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\ALIEN_MODULE\{AlienWeapon, AlienWeaponSpecials};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_12_07_06_01_05, shared: true)]
class IndexAlienWeaponTables implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = AlienWeapon::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->integer('type')->index()->change();
		});

		$table = AlienWeaponSpecials::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->integer('type')->index()->change();
		});
	}
}
