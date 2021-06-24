<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE\Migrations\Links;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateLinksTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "links";
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function(Blueprint $table) {
				$table->id("id")->change();
			});
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->string("name", 25);
			$table->string("website", 255);
			$table->string("comments", 255);
			$table->integer("dt");
		});
	}
}
