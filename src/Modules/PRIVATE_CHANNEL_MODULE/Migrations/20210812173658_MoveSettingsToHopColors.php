<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\ConfigFile;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\RouteHopColor;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;
use Nadybot\Core\SettingManager;

class MoveSettingsToHopColors implements SchemaMigration {
	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public MessageHub $messageHub;

	protected function getSettingColor(DB $db, string $name): ?string {
		/** @var ?Setting */
		$setting = $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
		if (!isset($setting) || ($setting->value??"") === "") {
			return null;
		}
		if (preg_match("/#([a-f0-9]{6})/i", $setting->value??"", $matches)) {
			return $matches[1];
		}
		return null;
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$hop = new RouteHopColor();
		$hop->tag_color = $this->getSettingColor($db, "guest_color_channel") ?? "C3C3C3";
		$hop->text_color = $this->getSettingColor($db, "guest_color_guild") ?? "C3C3C3";
		$hop->hop = Source::PRIV . "(" . $this->config->name . ")";
		$hop->id = $db->insert(MessageHub::DB_TABLE_COLORS, $hop);

		/** @psalm-suppress DocblockTypeContradiction */
		if (strlen($this->config->orgName)) {
			$hop = new RouteHopColor();
			$hop->tag_color = $this->getSettingColor($db, "guest_color_channel") ?? "C3C3C3";
			$hop->text_color = $this->getSettingColor($db, "guest_color_guest") ?? "C3C3C3";
			$hop->hop = Source::ORG . "({$this->config->orgName})";
			$hop->id = $db->insert(MessageHub::DB_TABLE_COLORS, $hop);
		}
	}
}
