<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

class AddIdColumn implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
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
