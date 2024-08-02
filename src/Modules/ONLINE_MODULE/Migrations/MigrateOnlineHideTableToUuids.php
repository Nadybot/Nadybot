<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\ONLINE_MODULE\{OnlineHide};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_02_15_25_00)]
class MigrateOnlineHideTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			OnlineHide::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('mask', 20)->unique();
				$table->string('created_by', 12);
				$table->unsignedInteger('created_on');
			},
			'id',
			'created_on'
		);
	}
}
