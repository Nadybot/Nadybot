<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateAltsTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "alts";
		if ($db->schema()->hasTable($table)) {
			if (!$db->schema()->hasColumn("alts", "validated")) {
				return;
			}
			$db->schema()->table("alts", function(Blueprint $table) {
				$table->renameColumn("validated", "validated_by_alt");
			});
			$db->schema()->table("alts", function(Blueprint $table) {
				$table->boolean("validated_by_alt")->nullable()->default(false)->change();
				$table->boolean("validated_by_main")->nullable()->default(false);
				$table->string("added_via", 15)->nullable();
			});
			$myName = $db->getMyname();
			$db->table("alts")->update(["validated_by_main" => true, "added_via" => $myName]);
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->string("alt", 25)->primary();
			$table->string("main", 25)->nullable();
			$table->boolean("validated_by_main")->nullable()->default(false);
			$table->boolean("validated_by_alt")->nullable()->default(false);
			$table->string("added_via", 15)->nullable();
		});
	}
}
