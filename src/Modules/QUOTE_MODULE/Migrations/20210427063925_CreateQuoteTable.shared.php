<?php declare(strict_types=1);

namespace Nadybot\Modules\QUOTE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateQuoteTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "quote";
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function(Blueprint $table) {
				$table->id("id")->change();
			});
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->string("poster", 25);
			$table->integer("dt");
			$table->string("msg", 1000);
		});
	}
}
