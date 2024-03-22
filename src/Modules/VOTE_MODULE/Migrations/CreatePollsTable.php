<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\VOTE_MODULE\VoteController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_428_082_915)]
class CreatePollsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = VoteController::DB_POLLS;
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, static function (Blueprint $table): void {
				$table->id('id')->change();
			});
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->string('author', 20);
			$table->text('question');
			$table->text('possible_answers');
			$table->integer('started');
			$table->integer('duration');
			$table->integer('status');
		});
	}
}
