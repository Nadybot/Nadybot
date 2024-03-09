<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOIS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

class CreateNameHistoryTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "name_history";
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->bigInteger("charid");
			$table->string("name", 20);
			$table->integer("dimension");
			$table->integer("dt");
			$table->primary(["charid", "name", "dimension"]);
		});
	}
}
