<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE\Migrations\Notes;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

class CreateNotesTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "notes";
		if ($db->schema()->hasTable($table)) {
			if (!$db->schema()->hasColumn($table, "reminder")) {
				$db->schema()->table($table, function (Blueprint $table): void {
					$table->integer("reminder")->default(0);
				});
			}
			$db->schema()->table($table, function (Blueprint $table): void {
				$table->id("id")->change();
			});
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->string("owner", 25);
			$table->string("added_by", 25);
			$table->text("note");
			$table->integer("dt");
			$table->integer("reminder")->default(0);
		});
	}
}
