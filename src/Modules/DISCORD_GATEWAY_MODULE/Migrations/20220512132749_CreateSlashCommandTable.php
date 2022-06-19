<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordSlashCommandController;

class CreateSlashCommandTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = DiscordSlashCommandController::DB_SLASH_TABLE;
		$db->schema()->create($table, function (Blueprint $table) {
			$table->id();
			$table->string('cmd', 50)->unique();
		});
		$db->table($table)->insert([
			["cmd" => "arbiter"],
			["cmd" => "attacks"],
			["cmd" => "checkaccess"],
			["cmd" => "cloak"],
			["cmd" => "extauth"],
			["cmd" => "father"],
			["cmd" => "gaubuff"],
			["cmd" => "gauntlet"],
			["cmd" => "help"],
			["cmd" => "history"],
			["cmd" => "hot"],
			["cmd" => "items"],
			["cmd" => "lc"],
			["cmd" => "loren"],
			["cmd" => "nano"],
			["cmd" => "nanolines"],
			["cmd" => "notes"],
			["cmd" => "online"],
			["cmd" => "penalty"],
			["cmd" => "reaper"],
			["cmd" => "sites"],
			["cmd" => "tara"],
			["cmd" => "track"],
			["cmd" => "victory"],
			["cmd" => "wb"],
			["cmd" => "weather"],
			["cmd" => "whatbuffs"],
			["cmd" => "whois"],
		]);
	}
}
