<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\FUN_MODULE\Death;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_19_20_04_17, shared: false)]
class CreateDeathTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Death::getTable();
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('character', 12)->primary();
			$table->integer('counter')->default(0);
		});
	}
}
