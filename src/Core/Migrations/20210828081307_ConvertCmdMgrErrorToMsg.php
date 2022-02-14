<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Nadybot\Core\{
	Attributes as NCA,
	DB,
	DBSchema\Route,
	DBSchema\Setting,
	LoggerWrapper,
	MessageHub,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};

class ConvertCmdMgrErrorToMsg implements SchemaMigration {
	#[NCA\Inject]
	public MessageHub $messageHub;

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = $this->messageHub::DB_TABLE_ROUTES;
		$errToOrg = $this->getSetting($db, "access_denied_notify_guild");
		$errToPriv = $this->getSetting($db, "access_denied_notify_priv");
		$toOrg = isset($errToOrg) ? ($errToOrg->value === "1") : true;
		$toPriv = isset($errToPriv) ? ($errToPriv->value === "1") : true;

		$botName = $db->getMyname();
		if ($toOrg) {
			$route = new Route();
			$route->source = Source::SYSTEM . "(access-denied)";
			$route->destination = Source::ORG;
			$db->insert($table, $route);
		}
		if ($toPriv) {
			$route = new Route();
			$route->source = Source::SYSTEM . "(access-denied)";
			$route->destination = Source::PRIV . "({$botName})";
			$db->insert($table, $route);
		}
	}
}
