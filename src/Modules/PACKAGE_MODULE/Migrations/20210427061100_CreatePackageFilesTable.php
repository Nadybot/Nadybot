<?php declare(strict_types=1);

namespace Nadybot\Modules\PACKAGE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\PACKAGE_MODULE\PackageController;

class CreatePackageFilesTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = PackageController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->string("module", 25)->index();
			$table->string("version", 50)->index();
			$table->text("file");
		});
	}
}
