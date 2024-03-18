<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210427053115)]
class CreateNewsConfirmedTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "news_confirmed";
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("id")->index();
			$table->string("player", 20)->index();
			$table->integer("time");
			$table->unique(["id", "player"]);
		});
	}
}
