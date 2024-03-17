<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\MigrationOrder(20211207060615)]
class AddIndexToMain implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "alts";
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->string("main", 25)->index()->change();
		});
	}
}
