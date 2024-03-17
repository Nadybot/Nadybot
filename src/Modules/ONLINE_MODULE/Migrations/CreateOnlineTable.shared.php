<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\MigrationOrder(20210427055146)]
class CreateOnlineTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "online";
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function (Blueprint $table): void {
				$table->string("name", 15)->change();
				$table->string("channel", 50)->nullable()->change();
				$table->string("channel_type", 10)->change();
				$table->string("added_by", 15)->change();
			});
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("name", 15);
			$table->string("afk", 255)->nullable()->default('');
			$table->string("channel", 50)->nullable();
			$table->string("channel_type", 10);
			$table->string("added_by", 15);
			$table->integer("dt");
			$table->unique(["name", "channel_type", "added_by"]);
		});
	}
}
