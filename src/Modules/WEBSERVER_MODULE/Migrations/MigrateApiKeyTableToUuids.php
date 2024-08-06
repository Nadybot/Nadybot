<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\WEBSERVER_MODULE\{ApiKey};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_06_07_25_00)]
class MigrateApiKeyTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			ApiKey::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('character', 12)->index();
				$table->string('token', 8)->unique();
				$table->unsignedBigInteger('last_sequence_nr')->default(0);
				$table->text('pubkey');
				$table->unsignedInteger('created');
			},
			'id',
			'created'
		);
	}
}
