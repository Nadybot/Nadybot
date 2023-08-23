<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class AddIdColumn implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "fun";
		if ($db->schema()->hasTable($table)) {
			$db->schema()->drop($table);
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->string("type", 15)->index();
			$table->text("content");
		});
	}
}
