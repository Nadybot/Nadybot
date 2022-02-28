<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateImplantTypeTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "ImplantType";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function(Blueprint $table): void {
			$table->integer("ImplantTypeID")->primary();
			$table->string("Name", 20);
			$table->string("ShortName", 10);
		});
	}
}
