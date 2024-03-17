<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE\Migrations\Perks;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\MigrationOrder(20210427142238)]
class CreatePerkLevelTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "perk_level";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->integer("aoid")->nullable();
			$table->integer("perk_id")->index();
			$table->integer("perk_level")->index();
			$table->integer("required_level")->index();
		});
	}
}
