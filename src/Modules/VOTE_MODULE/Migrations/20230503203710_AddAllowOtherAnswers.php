<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\VOTE_MODULE\VoteController;

class AddAllowOtherAnswers implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "";
		$table = VoteController::DB_POLLS;
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->boolean("allow_other_answers")->nullable(false)->default(true);
		});
	}
}
