<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\PRIVATE_CHANNEL_MODULE\PrivateChannelController;
use Psr\Log\LoggerInterface;

class CreateMembersTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = PrivateChannelController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("name", 25)->primary();
			$table->integer("autoinv")->nullable()->default(0);
		});
	}
}
