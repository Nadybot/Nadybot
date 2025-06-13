<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Core\DBSchema\Setting;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2025_02_11_15_51_34)]
class AddConfidentialToSettingsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Setting::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->boolean('confidential')->nullable(false)->default(false);
		});
	}
}
