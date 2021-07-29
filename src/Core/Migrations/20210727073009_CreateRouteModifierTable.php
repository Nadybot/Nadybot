<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\SchemaMigration;

class CreateRouteModifierTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTE_MODIFIER;
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->unsignedBigInteger("route_id")->index();
			$table->string("modifier", 100);
		});
	}
}
