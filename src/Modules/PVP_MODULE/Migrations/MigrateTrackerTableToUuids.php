<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\PVP_MODULE\{TrackerEntry};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_04_07_32_00)]
class MigrateTrackerTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$idMapping = $db->migrateIdToUuid(
			TrackerEntry::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('created_by', 12);
				$table->unsignedInteger('created_on');
				$table->string('expression');
				$table->string('events');
			},
			'id',
			'created_on'
		);

		foreach ($idMapping as $id => $uuid) {
			$db->table(Route::getTable())
				->where('source', "site-tracker({$id})")
				->update(['source' => "site-tracker({$uuid})"]);
			$db->table(Route::getTable())
				->where('destination', "site-tracker({$id})")
				->update(['destination' => "site-tracker({$uuid})"]);
		}
	}
}
