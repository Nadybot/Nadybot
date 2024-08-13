<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\SymbiantAbilityMatrix;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_13_12_21_00, shared: true)]
class CreateSymbiantAbilityMatrixTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = SymbiantAbilityMatrix::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->dropIfExists('SymbiantAbilityMatrix');
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('symbiant_id');
			$table->integer('ability_id');
			$table->integer('amount');
		});
	}
}
