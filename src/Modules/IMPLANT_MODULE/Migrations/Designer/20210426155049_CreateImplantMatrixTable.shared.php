<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class CreateImplantMatrixTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "ImplantMatrix";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("ID")->primary();
			$table->integer("ShiningID")->index();
			$table->integer("BrightID");
			$table->integer("FadedID");
			$table->integer("AbilityID");
			$table->integer("TreatQL1");
			$table->integer("AbilityQL1");
			$table->integer("TreatQL200");
			$table->integer("AbilityQL200");
			$table->integer("TreatQL201");
			$table->integer("AbilityQL201");
			$table->integer("TreatQL300");
			$table->integer("AbilityQL300");
		});
	}
}
