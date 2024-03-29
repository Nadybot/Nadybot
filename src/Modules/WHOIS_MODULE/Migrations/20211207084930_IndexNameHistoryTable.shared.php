<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOIS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class IndexNameHistoryTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "name_history";
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->index(["dimension", "name"]);
		});
	}
}
