<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\CommandManager;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Core\SettingManager;

class CreatePermissionSetMapping implements SchemaMigration {
	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	protected function getSettingValue(DB $db, string $name): ?string {
		$setting = $this->getSetting($db, $name);
		return isset($setting) ? $setting->value : null;
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = CommandManager::DB_TABLE_MAPPING;
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->string("name", 50);
			$table->string("source", 100)->unique();
			$table->string("symbol", 1)->default("!");
			$table->boolean("symbol_optional")->default(false);
			$table->boolean("feedback")->default(true);
		});
		$symbol = $this->getSettingValue($db, "symbol") ?? "!";
		$discordSymbol = $this->getSettingValue($db, "discord_symbol") ?? "!";
		$inserts = [
			[
				"name" => "msg",
				"source" => "console",
				"symbol" => $symbol,
				"symbol_optional" => true,
				"feedback" => true,
			],
			[
				"name" => "msg",
				"source" => "aotell(*)",
				"symbol" => $symbol,
				"symbol_optional" => true,
				"feedback" => true,
			],
			[
				"name" => "priv",
				"source" => "aopriv(" . ucfirst(strtolower(($this->getSettingValue($db, "default_private_channel") ?? $db->getMyname()))) . ")",
				"symbol" => $symbol,
				"symbol_optional" => false,
				"feedback" => (bool)($this->getSettingValue($db, "private_channel_cmd_feedback") ?? "1"),
			],
			[
				"name" => "guild",
				"source" => "aoorg",
				"symbol" => $symbol,
				"symbol_optional" => false,
				"feedback" => (bool)($this->getSettingValue($db, "guild_channel_cmd_feedback") ?? "1"),
			],
			[
				"name" => "msg",
				"source" => "discordmsg(*)",
				"symbol" => $discordSymbol,
				"symbol_optional" => true,
				"feedback" => (bool)($this->getSettingValue($db, "discord_unknown_cmd_errors") ?? "1"),
			],
			[
				"name" => "priv",
				"source" => "web",
				"symbol" => $symbol,
				"symbol_optional" => false,
				"feedback" => true,
			],
			[
				"name" => "msg",
				"source" => "api",
				"symbol" => "",
				"symbol_optional" => true,
				"feedback" => false,
			],
		];
		if ($this->getSettingValue($db, "discord_process_commands") === "1") {
			$inserts []= [
				"name" => "prod",
				"source" => "discordpriv(".
					($this->getSettingValue($db, "discord_process_commands_only_in") ?? "*").
				")",
				"symbol_optional" => false,
				"symbol" => $discordSymbol,
				"feedback" => (bool)($this->getSettingValue($db, "discord_unknown_cmd_errors") ?? "1"),
			];
		}
		$db->table($table)->insert($inserts);
	}
}
