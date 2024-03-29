<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE\Migrations;

use Nadybot\Core\{
	Attributes as NCA,
	ConfigFile,
	DB,
	DBSchema\Setting,
	LoggerWrapper,
	MessageHub,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};

class MoveSettingsToHopColors implements SchemaMigration {
	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public MessageHub $messageHub;

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$hop = [
			"tag_color" => $this->getSettingColor($db, "guest_color_channel") ?? "C3C3C3",
			"text_color" => $this->getSettingColor($db, "guest_color_guild") ?? "C3C3C3",
			"hop" => Source::PRIV . "(" . $this->config->name . ")",
		];
		$db->table(MessageHub::DB_TABLE_COLORS)->insert($hop);

		if (strlen($this->config->orgName)) {
			$hop = [
				"tag_color" => $this->getSettingColor($db, "guest_color_channel") ?? "C3C3C3",
				"text_color" => $this->getSettingColor($db, "guest_color_guest") ?? "C3C3C3",
				"hop" => Source::ORG . "({$this->config->orgName})",
			];
			$db->table(MessageHub::DB_TABLE_COLORS)->insert($hop);
		}
	}

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
}
