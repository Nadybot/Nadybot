<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\IMPLANT_MODULE\ImplantMatrix;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_13_09_26_01, shared: true)]
class CreateImplantMatrixTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = ImplantMatrix::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->dropIfExists('ImplantMatrix');
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('id')->primary();
			$table->integer('shining_id')->index();
			$table->integer('bright_id');
			$table->integer('faded_id');
			$table->integer('ability_id');
			$table->integer('treat_ql1');
			$table->integer('ability_ql1');
			$table->integer('treat_ql200');
			$table->integer('ability_ql200');
			$table->integer('treat_ql201');
			$table->integer('ability_ql201');
			$table->integer('treat_ql300');
			$table->integer('ability_ql300');
		});
	}
}
