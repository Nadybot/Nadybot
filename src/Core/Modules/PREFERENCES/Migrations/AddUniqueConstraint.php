<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PREFERENCES\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\Preferences;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_04_09_12_10_00)]
class AddUniqueConstraint implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Preferences::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->unique(['sender', 'name']);
		});
	}
}
