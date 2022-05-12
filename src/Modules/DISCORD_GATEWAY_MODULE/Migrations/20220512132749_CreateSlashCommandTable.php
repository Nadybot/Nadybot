<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordGatewayController;

class CreateSlashCommandTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = DiscordGatewayController::DB_SLASH_TABLE;
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->string('cmd', 50)->unique();
		});
		$db->table($table)->insert([
			["cmd" => "admin"],
			["cmd" => "adminlist"],
			["cmd" => "alts"],
			["cmd" => "arbiter"],
			["cmd" => "attacks"],
			["cmd" => "ban"],
			["cmd" => "boss"],
			["cmd" => "bossloot"],
			["cmd" => "checkaccess"],
			["cmd" => "cloak"],
			["cmd" => "config"],
			["cmd" => "debug"],
			["cmd" => "disc"],
			["cmd" => "dyna"],
			["cmd" => "extauth"],
			["cmd" => "father"],
			["cmd" => "gaubuff"],
			["cmd" => "gaulist"],
			["cmd" => "gauntlet"],
			["cmd" => "help"],
			["cmd" => "history"],
			["cmd" => "hot"],
			["cmd" => "items"],
			["cmd" => "lc"],
			["cmd" => "leprocs"],
			["cmd" => "level"],
			["cmd" => "loren"],
			["cmd" => "members"],
			["cmd" => "missions"],
			["cmd" => "mod"],
			["cmd" => "nano"],
			["cmd" => "nanolines"],
			["cmd" => "nanoloc"],
			["cmd" => "needsscout"],
			["cmd" => "news"],
			["cmd" => "notes"],
			["cmd" => "online"],
			["cmd" => "penalty"],
			["cmd" => "perks"],
			["cmd" => "quote"],
			["cmd" => "radio"],
			["cmd" => "reaper"],
			["cmd" => "settings"],
			["cmd" => "sites"],
			["cmd" => "spawntime"],
			["cmd" => "system"],
			["cmd" => "tara"],
			["cmd" => "timers"],
			["cmd" => "track"],
			["cmd" => "victory"],
			["cmd" => "wb"],
			["cmd" => "weather"],
			["cmd" => "whatbuffs"],
			["cmd" => "whois"],
		]);
	}
}
