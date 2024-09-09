<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\WHOIS_MODULE\NameHistory;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_11_29_08_02_10, shared: true)]
class AddTimeIndex implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = NameHistory::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->index('dt');
		});
	}
}
