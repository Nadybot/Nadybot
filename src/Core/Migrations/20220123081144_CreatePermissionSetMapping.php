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
			$table->string("permission_set", 50);
			$table->string("source", 100)->unique();
			$table->string("symbol", 1)->default("!");
			$table->boolean("symbol_optional")->default(false);
			$table->boolean("feedback")->default(true);
		});
		$symbol = $this->getSettingValue($db, "symbol") ?? "!";
		$discordSymbol = $this->getSettingValue($db, "discord_symbol") ?? "!";
		$inserts = [
			[
				"permission_set" => "msg",
				"source" => "console",
				"symbol" => $symbol,
				"symbol_optional" => true,
				"feedback" => true,
			],
			[
				"permission_set" => "msg",
				"source" => "aotell(*)",
				"symbol" => $symbol,
				"symbol_optional" => true,
				"feedback" => true,
			],
			[
				"permission_set" => "priv",
				"source" => "aopriv(" . strtolower(($this->getSettingValue($db, "default_private_channel") ?? $db->getMyname())) . ")",
				"symbol" => $symbol,
				"symbol_optional" => false,
				"feedback" => (bool)($this->getSettingValue($db, "private_channel_cmd_feedback") ?? "1"),
			],
			[
				"permission_set" => "guild",
				"source" => "aoorg",
				"symbol" => $symbol,
				"symbol_optional" => false,
				"feedback" => (bool)($this->getSettingValue($db, "guild_channel_cmd_feedback") ?? "1"),
			],
			[
				"permission_set" => "msg",
				"source" => "discordmsg(*)",
				"symbol" => $discordSymbol,
				"symbol_optional" => true,
				"feedback" => (bool)($this->getSettingValue($db, "discord_unknown_cmd_errors") ?? "1"),
			],
			[
				"permission_set" => "priv",
				"source" => "web",
				"symbol" => $symbol,
				"symbol_optional" => false,
				"feedback" => true,
			],
			[
				"permission_set" => "msg",
				"source" => "api",
				"symbol" => $symbol,
				"symbol_optional" => true,
				"feedback" => false,
			],
		];
		if ($this->getSettingValue($db, "discord_process_commands") === "1") {
			$discordChannel = $this->getSettingValue($db, "discord_process_commands_only_in") ?? "off";
			if ($discordChannel === "off") {
				$discordChannel = "*";
			}
			$inserts []= [
				"name" => "priv",
				"source" => "discordpriv({$discordChannel})",
				"symbol_optional" => false,
				"symbol" => $discordSymbol,
				"feedback" => (bool)($this->getSettingValue($db, "discord_unknown_cmd_errors") ?? "1"),
			];
		}
		$db->table($table)->insert($inserts);
	}
}
