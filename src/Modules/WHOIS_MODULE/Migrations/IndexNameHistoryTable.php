<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOIS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\WHOIS_MODULE\NameHistory;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_12_07_08_49_30, shared: true)]
class IndexNameHistoryTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = NameHistory::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->index(['dimension', 'name']);
		});
	}
}
