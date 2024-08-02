<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE\Migrations\Notes;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\NOTES_MODULE\Note;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_02_14_57_00, shared: true)]
class MigrateNotesTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			Note::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('owner', 25);
				$table->string('added_by', 25);
				$table->text('note');
				$table->integer('dt');
				$table->integer('reminder')->default(0);
			},
			'id',
			'dt'
		);
	}
}
