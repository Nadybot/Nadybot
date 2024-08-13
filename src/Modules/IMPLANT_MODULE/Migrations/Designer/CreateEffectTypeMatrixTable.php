<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\EffectTypeMatrix;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_13_10_54_01, shared: true)]
class CreateEffectTypeMatrixTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = EffectTypeMatrix::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->dropIfExists('EffectTypeMatrix');
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('id')->primary();
			$table->string('name', 20);
			$table->integer('min_val_low');
			$table->integer('max_val_low');
			$table->integer('min_val_high');
			$table->integer('max_val_high');
		});
	}
}
