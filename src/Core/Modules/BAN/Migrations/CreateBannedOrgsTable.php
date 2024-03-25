<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Modules\BAN\BanController;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_06_22_06_47_01)]
class CreateBannedOrgsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = BanController::DB_TABLE_BANNED_ORGS;
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->bigInteger('org_id')->primary();
			$table->string('banned_by', 15);
			$table->integer('start');
			$table->integer('end')->nullable()->index();
			$table->text('reason')->nullable();
		});
	}
}
