<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\WORLDBOSS_MODULE\WorldBossController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20220628053401)]
class MakeTimerOptional implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = WorldBossController::DB_TABLE;
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->unsignedInteger("timer")->nullable(true)->change();
		});
	}
}
