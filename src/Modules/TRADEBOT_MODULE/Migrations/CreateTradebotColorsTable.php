<?php declare(strict_types=1);

namespace Nadybot\Modules\TRADEBOT_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\TRADEBOT_MODULE\TradebotController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210428082130)]
class CreateTradebotColorsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = TradebotController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function (Blueprint $table): void {
				$table->id("id")->change();
			});
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->string("tradebot", 12)->index();
			$table->string("channel", 25)->default('*')->index();
			$table->string("color", 6);
			$table->unique(["tradebot", "channel"]);
		});
	}
}
