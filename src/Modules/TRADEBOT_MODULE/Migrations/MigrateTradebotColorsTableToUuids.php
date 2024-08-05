<?php declare(strict_types=1);

namespace Nadybot\Modules\TRADEBOT_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\TRADEBOT_MODULE\{TradebotColors};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_05_16_28_00)]
class MigrateTradebotColorsTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			TradebotColors::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('tradebot', 12)->index();
				$table->string('channel', 25)->default('*')->index();
				$table->string('color', 6);
				$table->unique(['tradebot', 'channel']);
			}
		);
	}
}
