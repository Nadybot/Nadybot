<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\WEBSERVER_MODULE\ApiController;

class CreateApiKeyTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = ApiController::DB_TABLE;
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->string("character", 12)->index();
			$table->string("token", 8)->unique();
			$table->unsignedBigInteger("last_sequence_nr")->default(0);
			$table->text("pubkey");
			$table->unsignedInteger("created");
		});
	}
}
