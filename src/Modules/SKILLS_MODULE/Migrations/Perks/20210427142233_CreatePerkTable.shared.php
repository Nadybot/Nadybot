<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE\Migrations\Perks;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreatePerkTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "perk";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->string("name", 30)->index();
			$table->string("expansion", 2);
			$table->text("description")->nullable();
		});
	}
}
