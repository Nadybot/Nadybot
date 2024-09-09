<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\VOTE_MODULE\Poll;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_05_03_20_37_10)]
class AddAllowOtherAnswers implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Poll::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->boolean('allow_other_answers')->nullable(false)->default(true);
		});
	}
}
