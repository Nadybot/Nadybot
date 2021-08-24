<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Migrations;

use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\RouteHopColor;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Modules\CONFIG\ConfigController;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;
use Nadybot\Core\SettingManager;

class MigrateRelayColors implements SchemaMigration {
	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public ConfigController $configController;

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	protected function getColor(DB $db, string ...$names): string {
		foreach ($names as $name) {
			$setting = $this->getSetting($db, $name);
			if (!isset($setting) || $setting->value === "<font color='#C3C3C3'>") {
				continue;
			}
			if (!preg_match("/#([A-F0-9]{6})/i", $setting->value, $matches)) {
				continue;
			}
			return $matches[1];
		}
		return "C3C3C3";
	}

	protected function saveColor(DB $db, string $hop, string $tag, string $text): void {
		$spec = new RouteHopColor();
		$spec->hop = $hop;
		$spec->tag_color = $tag;
		$spec->text_color = $text;
		$db->insert(MessageHub::DB_TABLE_COLORS, $spec);
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$relayType = $this->getSetting($db, "relaytype");
		$relayBot = $this->getSetting($db, "relaybot");
		if (isset($relayType) && isset($relayBot) && $relayBot->value !== 'Off') {
			$this->migrateRelayModuleColors($db);
		}
		$relayType = $this->getSetting($db, "arelaytype");
		$relayBot = $this->getSetting($db, "arelaybot");
		if (isset($relayType) && isset($relayBot) && $relayBot->value !== 'Off') {
			$this->migrateAllianceRelayModuleColors($db);
		}

		$this->messageHub->loadTagColor();
		if ($this->configController->toggleModule("ALLIANCE_RELAY_MODULE", "all", false)) {
			$logger->log(
				'WARN',
				"Found the ALLIANCE_RELAY_MODULE, converted all settings and ".
				"deactivated it. Please remove the module, so it cannot ".
				"interfere. It is not compatible with Nadybot 5.2.0 or newer."
			);
		}
	}

	protected function migrateAllianceRelayModuleColors(DB $db): void {
		$textColor = $tagColor = $this->getColor($db, "arelay_color_guild", "arelay_color_priv");
		$this->saveColor($db, Source::ORG, $tagColor, $textColor);
		$this->saveColor($db, Source::PRIV, $tagColor, $textColor);
	}

	protected function migrateRelayModuleColors(DB $db): void {
		$orgTagColor = $this->getColor($db, "relay_guild_tag_color_org", "relay_guild_tag_color_priv");
		$orgTextColor = $this->getColor($db, "relay_guild_color_org", "relay_guild_color_priv");
		$this->saveColor($db, Source::ORG, $orgTagColor, $orgTextColor);

		$privTagColor = $this->getColor($db, "relay_guest_tag_color_org", "relay_guest_tag_color_priv");
		$privTextColor = $this->getColor($db, "relay_guest_color_org", "relay_guest_color_priv");
		$this->saveColor($db, Source::PRIV, $privTagColor, $privTextColor);

		if ($this->getSetting($db, "default_guild_color") !== null) {
			$relaySysColor = $this->getColor($db, "default_guild_color");
			$this->settingManager->save("default_routed_sys_color", "<font color='#{$relaySysColor}'>");
			$this->saveColor($db, Source::SYSTEM, $relaySysColor, $relaySysColor);
		}
	}
}