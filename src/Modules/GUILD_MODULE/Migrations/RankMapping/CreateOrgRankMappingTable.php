<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE\Migrations\RankMapping;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\GUILD_MODULE\GuildRankController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_26_04_55_58)]
class CreateOrgRankMappingTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = GuildRankController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('access_level', 15)->primary();
			$table->integer('min_rank')->unique();
		});
	}
}
