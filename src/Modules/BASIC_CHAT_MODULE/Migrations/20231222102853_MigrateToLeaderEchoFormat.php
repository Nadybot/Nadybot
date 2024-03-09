<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE\Migrations;

use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\{DB, SchemaMigration, SettingManager};
use Psr\Log\LoggerInterface;

class MigrateToLeaderEchoFormat implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$setting = $this->getSetting($db, 'leaderecho_color');
		if (!isset($setting) || !isset($setting->value)) {
			return;
		}
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
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}
}
