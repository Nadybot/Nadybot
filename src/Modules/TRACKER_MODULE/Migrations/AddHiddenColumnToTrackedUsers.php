<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\TRACKER_MODULE\TrackedUser;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_10_05_10_27_49)]
class AddHiddenColumnToTrackedUsers implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = TrackedUser::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->boolean('hidden')->default(false);
		});
	}
}
