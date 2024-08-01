<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\FUN_MODULE\Fun;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_20_12_00, shared: true)]
class MigrateFunTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			Fun::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('type', 15)->index();
				$table->text('content');
			}
		);
	}
}
