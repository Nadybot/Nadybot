<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Base;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

class CreateImplantRequirementsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "implant_requirements";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("ql")->primary();
			$table->integer("treatment");
			$table->integer("ability");
			$table->integer("abilityShiny");
			$table->integer("abilityBright");
			$table->integer("abilityFaded");
			$table->integer("skillShiny");
			$table->integer("skillBright");
			$table->integer("skillFaded");
		});
	}
}
