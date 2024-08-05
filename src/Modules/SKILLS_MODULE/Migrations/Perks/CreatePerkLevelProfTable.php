<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE\Migrations\Perks;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\SKILLS_MODULE\PerkLevelProf;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_05_12_55_03, shared: true)]
class CreatePerkLevelProfTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = PerkLevelProf::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->uuid('perk_level_id')->index();
			$table->string('profession', 25)->index();
		});
	}
}
