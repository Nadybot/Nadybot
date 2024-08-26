<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE\Migrations\OrgNotes;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\NOTES_MODULE\OrgNote;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_04_26_09_34_29, shared: true)]
class CreateOrgNotesTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = OrgNote::getTable();
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->string('uuid', 36)->unique()->index();
			$table->string('added_by', 12);
			$table->integer('added_on');
			$table->text('note');
		});
	}
}
