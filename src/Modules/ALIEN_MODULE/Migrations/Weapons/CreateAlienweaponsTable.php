<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE\Migrations\Weapons;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\ALIEN_MODULE\AlienWeapon;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_25_13_00_28, shared: true)]
class CreateAlienweaponsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = AlienWeapon::getTable();
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('type');
			$table->string('name', 255);
		});
	}
}
