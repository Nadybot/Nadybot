<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE\Migrations\Research;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210426065028)]
class CreateResearchTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "research";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("level");
			$table->integer("sk");
			$table->integer("levelcap");
		});
	}
}
