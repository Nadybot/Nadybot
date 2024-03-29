<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class CreateOrganizationsTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "organizations";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table) {
			$table->unsignedInteger("id")->index();
			$table->string("name", 40)->index();
			$table->string("faction", 10);
			$table->unsignedInteger("num_members");
			$table->string("index", 6)->index();
			$table->string("governing_form", 10);
		});
	}
}
