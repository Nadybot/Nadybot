<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE\Migrations\Perks;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\SKILLS_MODULE\PerkLevelBuff;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_05_12_55_04, shared: true)]
class CreatePerkLevelBuffsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = PerkLevelBuff::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->uuid('perk_level_id')->index();
			$table->integer('skill_id')->index();
			$table->integer('amount');
		});
	}
}
