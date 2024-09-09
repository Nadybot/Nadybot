<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Raid;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\RAID_MODULE\{Raid, RaidLog};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_04_24_11_37_47)]
class AddMaxMembers implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Raid::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->unsignedInteger('max_members')->nullable(true);
		});

		$table = RaidLog::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->unsignedInteger('max_members')->nullable(true);
		});
	}
}
