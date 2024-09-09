<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE\Migrations\ArulSaba;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\RECIPE_MODULE\ArulSaba;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_27_13_24_48, shared: true)]
class CreateArulsabaTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = ArulSaba::getTable();
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('name', 20)->primary();
			$table->string('lesser_prefix', 10);
			$table->string('regular_prefix', 20);
			$table->string('buffs', 20);
		});
	}
}
