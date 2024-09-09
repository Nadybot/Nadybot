<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\WORLDBOSS_MODULE\WorldBossTimer;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_06_28_05_34_01)]
class MakeTimerOptional implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = WorldBossTimer::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->unsignedInteger('timer')->nullable(true)->change();
		});
	}
}
