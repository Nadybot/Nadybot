<?php declare(strict_types=1);

namespace Nadybot\Modules\QUOTE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\QUOTE_MODULE\Quote;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_04_07_57_00, shared: true)]
class MigrateQuoteTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			Quote::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('poster', 25);
				$table->integer('dt');
				$table->string('msg', 1_000);
			},
			'id',
			'dt',
		);
	}
}
