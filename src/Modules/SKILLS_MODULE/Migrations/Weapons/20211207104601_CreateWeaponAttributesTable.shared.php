<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE\Migrations\Weapons;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateWeaponAttributesTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "weapon_attributes";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function(Blueprint $table): void {
			$table->integer("id")->primary();
			$table->integer("attack_time");
			$table->integer("recharge_time");
			$table->integer("full_auto")->nullable();
			$table->integer("burst")->nullable();
			$table->tinyInteger("fling_shot");
			$table->tinyInteger("fast_attack");
			$table->tinyInteger("aimed_shot");
			$table->tinyInteger("brawl");
			$table->tinyInteger("sneak_attack");
			$table->integer("multi_m")->nullable();
			$table->integer("multi_r")->nullable();
		});
	}
}
