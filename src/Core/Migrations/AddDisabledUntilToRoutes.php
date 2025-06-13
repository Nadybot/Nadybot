<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Core\DBSchema\Route;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_06_24_12_15_01)]
class AddDisabledUntilToRoutes implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Route::getTable();
		if ($db->schema()->hasColumn($table, 'disabled_until')) {
			return;
		}
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->unsignedInteger('disabled_until')->nullable(true);
		});
	}
}
