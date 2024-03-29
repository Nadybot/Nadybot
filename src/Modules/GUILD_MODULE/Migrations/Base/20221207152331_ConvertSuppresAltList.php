<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE\Migrations\Base;

use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration, SettingManager};

class ConvertSuppresAltList implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = SettingManager::DB_TABLE;
		if (!$db->schema()->hasTable($table)) {
			return;
		}
		$oldValue = $this->getSetting($db, "org_suppress_alt_list");
		if (!isset($oldValue)) {
			return;
		}
		$db->table($table)->updateOrInsert(
			["name" => "org_logon_message"],
			[
				"name" => "org_logon_message",
				"module" => $oldValue->module,
				"type" => "text",
				"mode" => $oldValue->mode,
				"value" => ($oldValue->value === "1")
					? "{whois} logged on{?main:. {alt-of}}{?logon-msg: - {logon-msg}}"
					: "{whois} logged on{?main:. {alt-list}}{?logon-msg: - {logon-msg}}",
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
