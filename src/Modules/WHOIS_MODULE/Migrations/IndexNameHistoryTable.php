<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOIS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20211207084930)]
class IndexNameHistoryTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "name_history";
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->index(["dimension", "name"]);
		});
	}
}
