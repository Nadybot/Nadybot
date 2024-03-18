<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\DEV_MODULE\SilenceController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210425163859)]
class CreateSilenceCmdTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = SilenceController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("cmd", 25);
			$table->string("channel", 18);
		});
	}
}
