<?php declare(strict_types=1);

namespace Nadybot\Modules\HIGHNET_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\HIGHNET_MODULE\{FilterEntry};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_02_17_54_00)]
class MigrateFilterTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			FilterEntry::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('creator', 12)->nullable(false);
				$table->string('sender_name', 12)->nullable(true);
				$table->unsignedInteger('sender_uid')->nullable(true);
				$table->string('bot_name', 12)->nullable(true);
				$table->unsignedInteger('bot_uid')->nullable(true);
				$table->string('channel', 25)->nullable(true);
				$table->unsignedSmallInteger('dimension')->nullable(true);
				$table->unsignedInteger('expires')->nullable(true);
			},
			'id'
		);
	}
}
