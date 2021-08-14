<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\SchemaMigration;

class CreateRouteHopColorTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_COLORS;
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->string("hop", 25)->default('*')->unique();
			$table->string("tag_color", 6)->nullable();
			$table->string("text_color", 6)->nullable();
		});
	}
}
