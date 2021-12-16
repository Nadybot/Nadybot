<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Migrations\Items;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateItemDBs implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$db->schema()->dropIfExists("aodb");
		$db->schema()->create("aodb", function(Blueprint $table): void {
			$table->integer("lowid")->nullable()->index();
			$table->integer("highid")->nullable()->index();
			$table->integer("lowql")->nullable()->index();
			$table->integer("highql")->nullable()->index();
			$table->string("name", 150)->nullable()->index();
			$table->integer("icon")->nullable();
			$table->boolean("froob_friendly")->nullable()->index();
			$table->integer("slot")->nullable();
			$table->integer("flags")->nullable();
		});

		$db->schema()->dropIfExists("item_groups");
		$db->schema()->create("item_groups", function(Blueprint $table): void {
			$table->integer("id")->primary();
			$table->integer("group_id")->index();
			$table->integer("item_id")->index();
		});

		$db->schema()->dropIfExists("item_group_names");
		$db->schema()->create("item_group_names", function(Blueprint $table): void {
			$table->integer("group_id")->primary();
			$table->string("name", 150);
		});
	}
}
