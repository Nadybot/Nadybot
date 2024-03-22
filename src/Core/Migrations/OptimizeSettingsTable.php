<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration, SettingManager};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_211_018_065_415)]
class OptimizeSettingsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = SettingManager::DB_TABLE;
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->text('value')->nullable(true)->change();
			$table->text('options')->nullable(true)->change();
			$table->text('description')->nullable(true)->change();
		});
	}
}
