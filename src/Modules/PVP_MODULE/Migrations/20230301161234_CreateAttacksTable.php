<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\PVP_MODULE\NotumWarsController;

class CreateAttacksTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = NotumWarsController::DB_ATTACKS;
		$db->schema()->create($table, function (Blueprint $table) {
			$table->unsignedInteger("playfield_id");
			$table->unsignedTinyInteger("site_id");
			$table->unsignedInteger("location_x");
			$table->unsignedInteger("location_y");
			$table->unsignedInteger("timestamp");
			$table->string("att_name", 25);
			$table->string("att_faction", 7)->nullable(true);
			$table->string("att_org", 40)->nullable(true);
			$table->unsignedInteger("att_org_id")->nullable(true);
			$table->unsignedSmallInteger("att_level")->nullable(true);
			$table->unsignedSmallInteger("att_ai_level")->nullable(true);
			$table->string("att_profession", 15)->nullable(true);
			$table->string("att_org_rank", 20)->nullable(true);
			$table->string("att_breed", 10)->nullable(true);
			$table->string("att_gender", 10)->nullable(true);
			$table->unsignedInteger("att_uid")->nullable(true);
			$table->string("def_faction", 7);
			$table->string("def_org", 40);

			$table->index("playfield_id");
			$table->index("site_id");
			$table->index("timestamp");
			$table->index("att_org");
			$table->index("def_org");
			$table->index("att_name");
		});
	}
}
