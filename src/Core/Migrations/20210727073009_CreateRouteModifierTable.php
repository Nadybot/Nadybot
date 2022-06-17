<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, MessageHub, SchemaMigration};

class CreateRouteModifierTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTE_MODIFIER;
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->unsignedBigInteger("route_id")->index();
			$table->string("modifier", 100);
		});
	}
}
