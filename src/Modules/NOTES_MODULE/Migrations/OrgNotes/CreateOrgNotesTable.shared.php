<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE\Migrations\OrgNotes;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\NOTES_MODULE\OrgNotesController;
use Psr\Log\LoggerInterface;

#[NCA\MigrationOrder(20220426093429)]
class CreateOrgNotesTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = OrgNotesController::DB_TABLE;
		$db->schema()->create($table, function (Blueprint $table) {
			$table->id();
			$table->string("uuid", 36)->unique()->index();
			$table->string("added_by", 12);
			$table->integer("added_on");
			$table->text("note");
		});
	}
}
