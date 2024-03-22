<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{AdminManager, DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_422_152_201)]
class CreateAdminTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = AdminManager::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('name', 25)->primary();
			$table->integer('adminlevel')->nullable();
		});
	}
}
