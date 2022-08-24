<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordGatewayCommandHandler;

class CreateDiscordMappingTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = DiscordGatewayCommandHandler::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("name", 12);
			$table->string("discord_id", 50);
			$table->string("token", 32)->nullable();
			$table->integer("created");
			$table->integer("confirmed")->nullable();
			$table->unique(["name", "discord_id"]);
		});
	}
}
