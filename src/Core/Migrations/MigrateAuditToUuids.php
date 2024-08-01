<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\Audit;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_09_07_01)]
class MigrateAuditToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			Audit::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('actor', 12)->index();
				$table->string('actee', 12)->nullable()->index();
				$table->string('action', 20)->index();
				$table->text('value')->nullable();
				$table->integer('time')->index();
			},
			'id',
			'time'
		);
	}
}
