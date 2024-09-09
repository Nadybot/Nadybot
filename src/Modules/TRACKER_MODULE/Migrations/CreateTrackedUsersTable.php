<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\TRACKER_MODULE\TrackedUser;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_28_06_11_40)]
class CreateTrackedUsersTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = TrackedUser::getTable();
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->bigInteger('uid')->primary();
			$table->string('name', 25);
			$table->string('added_by', 25);
			$table->integer('added_dt');
		});
	}
}
