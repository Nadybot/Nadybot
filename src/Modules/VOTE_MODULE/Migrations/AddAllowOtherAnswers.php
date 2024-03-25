<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\VOTE_MODULE\VoteController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_05_03_20_37_10)]
class AddAllowOtherAnswers implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = '';
		$table = VoteController::DB_POLLS;
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->boolean('allow_other_answers')->nullable(false)->default(true);
		});
	}
}
