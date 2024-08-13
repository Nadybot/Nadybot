<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\Symbiant;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_13_13_45_00, shared: true)]
class CreateSymbiantTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Symbiant::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->dropIfExists('Symbiant');
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('id')->primary();
			$table->string('name', 100);
			$table->integer('ql');
			$table->integer('slot_id');
			$table->integer('treatment_req');
			$table->integer('level_req');
			$table->string('unit', 20);
		});
	}
}
