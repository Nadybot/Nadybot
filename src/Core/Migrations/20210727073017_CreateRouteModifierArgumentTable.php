<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, MessageHub, SchemaMigration};

class CreateRouteModifierArgumentTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT;
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->unsignedBigInteger("route_modifier_id")->index();
			$table->string("name", 100);
			$table->string("value", 200);
		});
	}
}
