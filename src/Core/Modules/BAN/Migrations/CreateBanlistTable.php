<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Modules\BAN\BanController;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_23_12_10_37)]
class CreateBanlistTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = BanController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->bigInteger('charid')->primary();
			$table->string('admin', 25)->nullable();
			$table->integer('time')->nullable();
			$table->text('reason')->nullable();
			$table->integer('banend')->nullable()->index();
		});
	}
}
