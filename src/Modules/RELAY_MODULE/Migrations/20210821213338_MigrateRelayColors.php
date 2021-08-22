<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Migrations;

use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\RouteHopColor;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;
use Nadybot\Core\SettingManager;

class MigrateRelayColors implements SchemaMigration {
	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public MessageHub $messageHub;

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	protected function getColor(DB $db, string ...$names): string {
		foreach ($names as $name) {
			$setting = $this->getSetting($db, $name);
			if (!isset($setting) || $setting->value !== "<font color='#C3C3C3'>") {
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
		$orgTagColor = $this->getColor($db, "relay_guild_tag_color_org", "relay_guild_tag_color_priv");
		$orgTextColor = $this->getColor($db, "relay_guild_color_org", "relay_guild_color_priv");
		$this->saveColor($db, Source::ORG, $orgTagColor, $orgTextColor);

		$privTagColor = $this->getColor($db, "relay_guest_tag_color_org", "relay_guest_tag_color_priv");
		$privTextColor = $this->getColor($db, "relay_guest_color_org", "relay_guest_color_priv");
		$this->saveColor($db, Source::PRIV, $privTagColor, $privTextColor);

		$relaySysColor = $this->getSetting($db, "relay_color_guild")
			?? $this->getSetting($db, "relay_color_priv");
		if (isset($relaySysColor)) {
			$this->settingManager->save("default_routed_sys_color", $relaySysColor->value);
		}
		$this->messageHub->loadTagColor();
	}
}
