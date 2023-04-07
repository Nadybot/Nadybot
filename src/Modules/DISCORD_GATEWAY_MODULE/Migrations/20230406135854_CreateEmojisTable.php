<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordGatewayController;

class CreateEmojisTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = DiscordGatewayController::EMOJI_TABLE;
		$db->schema()->create($table, function (Blueprint $table) {
			$table->id();
			$table->string("name", 20)->index();
			$table->unsignedInteger("registered");
			$table->unsignedInteger("version");
			$table->string("guild_id", 24);
		});
	}
}
