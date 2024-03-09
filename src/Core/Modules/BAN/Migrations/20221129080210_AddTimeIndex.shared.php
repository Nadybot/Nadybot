<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

class AddTimeIndex implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "name_history";
		$db->schema()->table($table, function (Blueprint $table) {
			$table->index("dt");
		});
	}
}
