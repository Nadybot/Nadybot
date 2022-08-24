<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Migrations;

use Nadybot\Core\{
	DB,
	DBSchema\Setting,
	LoggerWrapper,
	MessageHub,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};

class MigrateVoiceStateToRoutes implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$setting = $this->getSetting($db, 'discord_notify_voice_changes');
		if (!isset($setting) || !isset($setting->value)) {
			return;
		}
		if ((int)$setting->value & 1) {
			$route = [
				"source" => Source::DISCORD_PRIV . "(< *)",
				"destination" => Source::PRIV . "(" . $db->getBotname() . ")",
				"two_way" => false,
			];
			$db->table(MessageHub::DB_TABLE_ROUTES)->insert($route);
		}
		if ((int)$setting->value & 2) {
			$route = [
				"source" => Source::DISCORD_PRIV . "(< *)",
				"destination" => Source::ORG,
				"two_way" => false,
			];
			$db->table(MessageHub::DB_TABLE_ROUTES)->insert($route);
		}
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}
}
