<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DB,
	DBSchema\Setting,
	MessageHub,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};
use Nadybot\Modules\VOTE_MODULE\VoteController;
use Psr\Log\LoggerInterface;

#[NCA\MigrationOrder(20210828154348)]
class MigrateToRoutes implements SchemaMigration {
	#[NCA\Inject]
	private VoteController $voteController;

	#[NCA\Inject]
	private BotConfig $config;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTES;
		$showWhere = $this->getSetting($db, "vote_channel_spam");
		if (!isset($showWhere)) {
			if (strlen($this->config->general->orgName)) {
				$showWhere = 2;
			} else {
				$showWhere = 0;
			}
		} else {
			$showWhere = (int)$showWhere->value;
		}
		if (in_array($showWhere, [0, 2])) {
			$route = [
				"source" => $this->voteController->getChannelName(),
				"destination" => Source::PRIV . "(" . $db->getMyname() . ")",
				"two_way" => false,
			];
			$db->table($table)->insert($route);
		}
		if (in_array($showWhere, [1, 2])) {
			$route = [
				"source" => $this->voteController->getChannelName(),
				"destination" => Source::ORG,
				"two_way" => false,
			];
			$db->table($table)->insert($route);
		}
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}
}
