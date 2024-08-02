<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE\Migrations\OrgNotes;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\NOTES_MODULE\{OrgNote};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_02_15_06_00, shared: true)]
class MigrateOrgNotesTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table =  OrgNote::getTable();
		$entries = $db->table($table)->orderBy('id')->get();
		$db->schema()->drop($table);
		$db->schema()->create(
			$table,
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('added_by', 12);
				$table->integer('added_on');
				$table->text('note');
			}
		);

		/** @return array<string,mixed> */
		$entries = $entries->map(static function (\stdClass $entry): array {
			$new = (array)$entry;
			$new['id'] = $new['uuid'];
			unset($new['uuid']);
			return $new;
		})->toList();
		$db->table($table)->chunkInsert($entries);
	}
}
