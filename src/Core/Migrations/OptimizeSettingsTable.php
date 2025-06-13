<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Core\DBSchema\Setting;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_10_18_06_54_15)]
class OptimizeSettingsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Setting::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->text('value')->nullable(true)->change();
			$table->text('options')->nullable(true)->change();
			$table->text('description')->nullable(true)->change();
		});
	}
}
