<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210426160022)]
class CreateSymbiantTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "Symbiant";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("ID")->primary();
			$table->string("Name", 100);
			$table->integer("QL");
			$table->integer("SlotID");
			$table->integer("TreatmentReq");
			$table->integer("LevelReq");
			$table->string("Unit", 20);
		});
	}
}
