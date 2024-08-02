<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE\Migrations\Arbiter;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	DB,
	SchemaMigration,
};
use Nadybot\Modules\HELPBOT_MODULE\{ICCArbiter};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_12_25_00, shared: true)]
class MigrateArbiterTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			ICCArbiter::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('type', 3)->unique();
				$table->unsignedInteger('start')->index();
				$table->unsignedInteger('end')->index();
			}
		);
	}
}
