<?php declare(strict_types=1);

namespace Nadybot\Modules\NANO_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\NANO_MODULE\Nanoline;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_26_17_53_41, shared: true)]
class CreateNanoLinesTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Nanoline::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('strain_id')->primary();
			$table->string('name', 50);
		});
	}
}
