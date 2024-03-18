<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE\Migrations\ArulSaba;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210427132448)]
class CreateArulsabaTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "arulsaba";
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("name", 20)->primary();
			$table->string("lesser_prefix", 10);
			$table->string("regular_prefix", 20);
			$table->string("buffs", 20);
		});
	}
}
