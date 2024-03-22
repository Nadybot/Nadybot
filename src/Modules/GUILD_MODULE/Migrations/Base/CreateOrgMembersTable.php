<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE\Migrations\Base;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\GUILD_MODULE\GuildController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_426_045_214)]
class CreateOrgMembersTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = GuildController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('name', 25)->primary();
			$table->string('mode', 7)->nullable();
			$table->integer('logged_off')->nullable()->default(0);
		});
	}
}
