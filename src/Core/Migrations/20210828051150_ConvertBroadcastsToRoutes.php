<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;
use Nadybot\Core\SettingManager;

class ConvertBroadcastsToRoutes implements SchemaMigration {
	/** @Inject */
	public Nadybot $chatBot;

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "broadcast_<myname>";
		if (!$db->schema()->hasTable($table)) {
			return;
		}
		$broadcasts = $db->table($table)->asObj();
		$orgSetting = $this->getSetting($db, 'broadcast_to_guild');
		$toOrg = isset($orgSetting) ? ($orgSetting->value === "1") : true;
		$privSetting = $this->getSetting($db, 'broadcast_to_privchan');
		$toPriv = isset($privSetting) ? ($privSetting->value === "1") : true;
		foreach ($broadcasts as $broadcast) {
			$this->convertBroadcastToRoute($db, $broadcast, $toOrg, $toPriv);
		}
		$db->schema()->dropIfExists($table);
	}

	public function convertBroadcastToRoute(DB $db, object $broadcast, bool $org, bool $priv): void {
		$name = ucfirst(strtolower($broadcast->name));
		$botName = $this->chatBot->vars['myname'];
		if ($org) {
			$route = new Route();
			$route->source = Source::TELL . "({$name})";
			$route->destination = Source::ORG;
			$db->insert(MessageHub::DB_TABLE_ROUTES, $route);
		}
		if ($priv) {
			$route = new Route();
			$route->source = Source::TELL . "({$name})";
			$route->destination = Source::PRIV . "({$botName})";
			$db->insert(MessageHub::DB_TABLE_ROUTES, $route);
		}
	}
}
