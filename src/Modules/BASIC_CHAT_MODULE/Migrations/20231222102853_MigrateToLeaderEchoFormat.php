<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE\Migrations;

use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Core\SettingManager;

class MigrateToLeaderEchoFormat implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$setting = $this->getSetting($db, 'leaderecho_color');
		if (!isset($setting) || !isset($setting->value)) {
			return;
		}
		var_dump($setting);
		$db->table(SettingManager::DB_TABLE)
			->updateOrInsert(
				["name" => "leader_echo_format"],
				[
					"name" => "leader_echo_format",
					"module" => $setting->module,
					"type" => "text",
					"mode" => $setting->mode,
					"value" => ($setting->value === "<font color='#FFFF00'>")
						? "<yellow>{message}<end>"
						: "{$setting->value}{message}</font>",
					"options" => "",
					"intoptions" => "",
					"description" => "Dummy",
					"source" => $setting->source,
					"admin" => $setting->admin,
					"verify" => "0",
				],
			);
		throw new \Exception("Juchei!");
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}
}
