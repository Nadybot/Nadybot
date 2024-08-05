<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE\Migrations\Perks;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\SKILLS_MODULE\PerkLevel;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_05_12_55_00, shared: true)]
class CreatePerkLevelTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = PerkLevel::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->uuid('id')->primary();
			$table->integer('aoid')->nullable();
			$table->uuid('perk_id')->index();
			$table->integer('perk_level')->index();
			$table->integer('required_level')->index();
		});
	}
}
