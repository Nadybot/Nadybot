<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE\Migrations\Formula;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\HELPBOT_MODULE\Formula;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_12_13_15_35_00, shared: false)]
class CreateFormulaTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Formula::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('name', 20)->primary();
			$table->string('formula');
		});
	}
}
