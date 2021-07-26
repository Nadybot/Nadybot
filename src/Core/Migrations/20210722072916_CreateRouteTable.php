<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\SchemaMigration;

class CreateRouteTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTES;
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->string("source", 100);
			$table->string("destination", 100);
			$table->boolean("two_way")->default(false);
		});
	}
}
