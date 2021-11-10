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
			if (!preg_match("/#([A-F0-9]{6})/i", $setting->value??"", $matches)) {
				continue;
			}
			return $matches[1];
		}
		return "C3C3C3";
	}

	protected function saveColor(DB $db, string $hop, ?string $where, ?string $tag, string $text): void {
		$spec = new RouteHopColor();
		$spec->hop = $hop;
		$spec->where = $where;
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
		$textColorOrg = $tagColor = $this->getColor($db, "arelay_color_guild");
		$this->saveColor($db, Source::ORG, null, $tagColor, $textColorOrg);
		$this->saveColor($db, Source::PRIV, null, $tagColor, $textColorOrg);

		$textColorPriv = $tagColor = $this->getColor($db, "arelay_color_priv");
		if ($textColorOrg !== $textColorPriv) {
			$this->saveColor($db, Source::ORG, Source::PRIV, $tagColor, $textColorPriv);
			$this->saveColor($db, Source::PRIV, Source::PRIV, $tagColor, $textColorPriv);
		}
	}

	protected function migrateRelayModuleColors(DB $db): void {
		$privChannel = Source::PRIV . "(" . $db->getMyname() . ")";
		$orgTagColor = $this->getColor($db, "relay_guild_tag_color_org");
		$orgTextColor = $this->getColor($db, "relay_guild_color_org");
		$this->saveColor($db, Source::ORG, null, $orgTagColor, $orgTextColor);

		$orgTagColorPriv = $this->getColor($db, "relay_guild_tag_color_priv");
		$orgTextColorPriv = $this->getColor($db, "relay_guild_color_priv");
		if ($orgTagColor !== $orgTagColorPriv || $orgTextColor !== $orgTextColorPriv) {
			$this->saveColor($db, Source::ORG, $privChannel, $orgTagColorPriv, $orgTextColorPriv);
		}

		$privTagColor = $this->getColor($db, "relay_guest_tag_color_org");
		$privTextColor = $this->getColor($db, "relay_guest_color_org");
		$this->saveColor($db, Source::PRIV, null, $privTagColor, $privTextColor);

		$privTagColorPriv = $this->getColor($db, "relay_guest_tag_color_priv");
		$privTextColorPriv = $this->getColor($db, "relay_guest_color_priv");
		if ($privTagColor !== $privTagColorPriv || $privTextColor !== $privTextColorPriv) {
			$this->saveColor($db, Source::PRIV, $privChannel, $privTagColorPriv, $privTextColorPriv);
		}

		if ($this->getSetting($db, "default_guild_color") !== null) {
			$relaySysColor = $this->getColor($db, "default_guild_color");
			$this->settingManager->save("default_routed_sys_color", "<font color='#{$relaySysColor}'>");
			$this->saveColor($db, Source::SYSTEM, Source::ORG, null, $relaySysColor);
		}
	}
}
