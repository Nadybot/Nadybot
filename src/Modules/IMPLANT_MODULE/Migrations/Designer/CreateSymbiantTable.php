<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_26_16_00_22, shared: true)]
class CreateSymbiantTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'Symbiant';
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('ID')->primary();
			$table->string('Name', 100);
			$table->integer('QL');
			$table->integer('SlotID');
			$table->integer('TreatmentReq');
			$table->integer('LevelReq');
			$table->string('Unit', 20);
		});
	}
}
