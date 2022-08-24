<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, MessageHub, SchemaMigration};

class AddDisabledUntilToRoutes implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTES;
		if ($db->schema()->hasColumn($table, "disabled_until")) {
			return;
		}
		$db->schema()->table($table, function (Blueprint $table) {
			$table->unsignedInteger("disabled_until")->nullable(true);
		});
	}
}
