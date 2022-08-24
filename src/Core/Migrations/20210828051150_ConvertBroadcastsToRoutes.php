<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Nadybot\Core\{
	DB,
	DBSchema\Setting,
	LoggerWrapper,
	MessageHub,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};

class ConvertBroadcastsToRoutes implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "broadcast_<myname>";
		if (!$db->schema()->hasTable($table)) {
			return;
		}

		$broadcasts = $db->table($table)->pluckStrings("name");
		$orgSetting = $this->getSetting($db, 'broadcast_to_guild');
		$toOrg = isset($orgSetting) ? ($orgSetting->value === "1") : true;
		$privSetting = $this->getSetting($db, 'broadcast_to_privchan');
		$toPriv = isset($privSetting) ? ($privSetting->value === "1") : true;
		foreach ($broadcasts as $broadcast) {
			$this->convertBroadcastToRoute($db, $broadcast, $toOrg, $toPriv);
		}
		$db->schema()->dropIfExists($table);
	}

	public function convertBroadcastToRoute(DB $db, string $broadcast, bool $org, bool $priv): void {
		$name = ucfirst(strtolower($broadcast));
		$botName = $db->getMyname();
		if ($org) {
			$route = [
				"source" => Source::TELL . "({$name})",
				"destination" => Source::ORG,
				"two_way" => false,
			];
			$db->table(MessageHub::DB_TABLE_ROUTES)->insert($route);
		}
		if ($priv) {
			$route = [
				"source" => Source::TELL . "({$name})",
				"destination" => Source::PRIV . "({$botName})",
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
