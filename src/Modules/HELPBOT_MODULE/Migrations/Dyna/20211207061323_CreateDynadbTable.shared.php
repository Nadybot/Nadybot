<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE\Migrations\Dyna;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

class CreateDynadbTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "dynadb";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->unsignedInteger("playfield_id")->index();
			$table->string("mob", 20)->index();
			$table->unsignedInteger("min_ql")->index();
			$table->unsignedInteger("max_ql")->index();
			$table->unsignedInteger("x_coord");
			$table->unsignedInteger("y_coord");
		});
	}
}
