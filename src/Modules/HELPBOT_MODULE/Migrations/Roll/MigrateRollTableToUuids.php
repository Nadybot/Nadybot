<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE\Migrations\Roll;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\HELPBOT_MODULE\Roll;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_04_07_08_00, shared: true)]
class MigrateRollTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			Roll::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->integer('time')->nullable();
				$table->string('name', 255)->nullable();
				$table->text('options')->nullable();
				$table->string('result', 255)->nullable();
			},
			'id',
			'time'
		);
	}
}
