<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE\Migrations;

use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\{DB, SchemaMigration, SettingManager};
use Psr\Log\LoggerInterface;

class ConvertSuppresAltList implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = SettingManager::DB_TABLE;
		if (!$db->schema()->hasTable($table)) {
			return;
		}
		$oldValue = $this->getSetting($db, "priv_suppress_alt_list");
		if (!isset($oldValue)) {
			return;
		}
		$db->table($table)->updateOrInsert(
			["name" => "priv_join_message"],
			[
				"name" => "priv_join_message",
				"module" => $oldValue->module,
				"type" => "text",
				"mode" => $oldValue->mode,
				"value" => ($oldValue->value === "1")
					? "{whois} has joined {channel-name}. {alt-of}"
					: "{whois} has joined {channel-name}. {alt-list}",
				"options" => "",
				"intoptions" => "",
				"description" => "Dummy",
				"source" => $oldValue->source,
				"admin" => $oldValue->admin,
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
