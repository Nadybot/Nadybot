<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE\Migrations\Perks;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210427142306)]
class CreatePerkLevelResistancesTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "perk_level_resistances";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("perk_level_id")->index();
			$table->integer("strain_id");
			$table->integer("amount");
		});
	}
}
