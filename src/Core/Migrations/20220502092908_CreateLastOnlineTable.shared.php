<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateLastOnlineTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "last_online";
		$db->schema()->create($table, function(Blueprint $table) {
			$table->unsignedInteger("uid")->unique();
			$table->string("name", 12)->index();
			$table->unsignedInteger("dt");
		});
	}
}
