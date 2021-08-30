<?php declare(strict_types=1);

namespace Nadybot\Modules\TRADEBOT_MODULE\Migrations;

use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;
use Nadybot\Core\SettingManager;

class MigrateToRouting implements SchemaMigration {
	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Nadybot $chatBot;

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = $this->messageHub::DB_TABLE_ROUTES;
		$tradebot = $this->getSetting($db, 'tradebot');
		if (!isset($tradebot) || ($tradebot->value === "None")) {
			return;
		}
		$channels = $this->getSetting($db, 'tradebot_channel_spam');
		if (!isset($channels)) {
			return;
		}
		if ((int)$channels->value & 1) {
			$route = new Route();
			$route->source = Source::TRADEBOT;
			$route->destination = Source::PRIV . "({$this->chatBot->vars['name']})";
			$db->insert($table, $route);
		}
		if ((int)$channels->value & 2) {
			$route = new Route();
			$route->source = Source::TRADEBOT;
			$route->destination = Source::ORG;
			$db->insert($table, $route);
		}
	}
}
