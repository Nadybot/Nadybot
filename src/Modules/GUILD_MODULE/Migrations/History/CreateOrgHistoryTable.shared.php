<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE\Migrations\History;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\MigrationOrder(20210426050303)]
class CreateOrgHistoryTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "org_history";
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function (Blueprint $table): void {
				$table->id("id")->change();
			});
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->text("actor")->nullable();
			$table->text("actee")->nullable();
			$table->text("action")->nullable();
			$table->text("organization")->nullable();
			$table->integer("time")->nullable();
		});
	}
}
