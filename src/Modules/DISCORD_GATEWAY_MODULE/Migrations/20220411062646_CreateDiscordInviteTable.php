<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordGatewayController;

class CreateDiscordInviteTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = DiscordGatewayController::DB_TABLE;
		$db->schema()->create($table, function (Blueprint $table) {
			$table->id();
			$table->string("token", 10)->unique();
			$table->string("character", 12);
			$table->unsignedInteger("expires")->nullable()->index();
		});
	}
}
