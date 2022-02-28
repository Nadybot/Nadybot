<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE\Migrations;

use Nadybot\Core\{
	Attributes as NCA,
	ConfigFile,
	DB,
	DBSchema\Route,
	DBSchema\Setting,
	LoggerWrapper,
	MessageHub,
	Nadybot,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};
use Nadybot\Modules\VOTE_MODULE\VoteController;

class MigrateToRoutes implements SchemaMigration {
	#[NCA\Inject]
	public VoteController $voteController;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

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
			if (strlen($this->config->orgName)) {
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
