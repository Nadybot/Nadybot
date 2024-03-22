<?php declare(strict_types=1);

namespace Nadybot\Modules\NANO_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_426_175_341, shared: true)]
class CreateNanoLinesTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'nano_lines';
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('strain_id')->primary();
			$table->string('name', 50);
		});
	}
}
