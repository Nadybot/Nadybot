<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

class CreateImplantDesignTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "implant_design";
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("name", 50);
			$table->string("owner", 20);
			$table->integer("dt")->nullable();
			$table->text("design")->nullable();
		});
	}
}
