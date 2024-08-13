<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Premade;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\PremadeImplant;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_13_11_49_00, shared: true)]
class CreatePremadeImplantTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = PremadeImplant::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('implant_type_id');
			$table->integer('profession_id');
			$table->integer('ability_id');
			$table->integer('shiny_cluster_id');
			$table->integer('bright_cluster_id');
			$table->integer('faded_cluster_id');
		});
	}
}
