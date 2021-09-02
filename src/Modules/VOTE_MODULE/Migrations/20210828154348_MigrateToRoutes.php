<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE\Migrations;

use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;
use Nadybot\Core\SettingManager;
use Nadybot\Modules\VOTE_MODULE\VoteController;

class MigrateToRoutes implements SchemaMigration {
	/** @Inject */
	public VoteController $voteController;

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTES;
		$showWhere = $this->getSetting($db, "vote_channel_spam");
		if (!isset($showWhere)) {
			if (strlen($this->chatBot->vars['my_guild']??"")) {
				$showWhere = 2;
			} else {
				$showWhere = 0;
			}
		} else {
			$showWhere = (int)$showWhere->value;
		}
		if (in_array($showWhere, [0, 2])) {
			$route = new Route();
			$route->source = $this->voteController->getChannelName();
			$route->destination = Source::PRIV . "(" . $db->getMyname() . ")";
			$db->insert($table, $route);
		}
		if (in_array($showWhere, [1, 2])) {
			$route = new Route();
			$route->source = $this->voteController->getChannelName();
			$route->destination = Source::ORG;
			$db->insert($table, $route);
		}
	}
}
