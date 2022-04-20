<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Migrations;

use Nadybot\Core\{
	DB,
	DBSchema\Route,
	DBSchema\Setting,
	LoggerWrapper,
	MessageHub,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};

class MigrateVoiceStateToRoutes implements SchemaMigration {
	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$setting = $this->getSetting($db, 'discord_notify_voice_changes');
		if (!isset($setting) || !isset($setting->value)) {
			return;
		}
		if ((int)$setting->value & 1) {
			$route = new Route();
			$route->source = Source::DISCORD_PRIV . "(< *)";
			$route->destination = Source::PRIV . "(" . $db->getBotname() . ")";
			$db->insert(MessageHub::DB_TABLE_ROUTES, $route);
		}
		if ((int)$setting->value & 2) {
			$route = new Route();
			$route->source = Source::DISCORD_PRIV . "(< *)";
			$route->destination = Source::ORG;
			$db->insert(MessageHub::DB_TABLE_ROUTES, $route);
		}
	}
}
