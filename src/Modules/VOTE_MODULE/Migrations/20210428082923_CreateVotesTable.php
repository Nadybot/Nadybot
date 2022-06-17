<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\VOTE_MODULE\VoteController;

class CreateVotesTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = VoteController::DB_VOTES;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("poll_id");
			$table->string("author", 20);
			$table->text("answer")->nullable();
			$table->integer("time")->nullable();
			$table->unique(["poll_id", "author"]);
		});
	}
}
