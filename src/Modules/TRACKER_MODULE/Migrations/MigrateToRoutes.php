<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE\Migrations;

use Exception;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DB,
	DBSchema\Route,
	DBSchema\Setting,
	MessageHub,
	Modules\DISCORD\DiscordAPIClient,
	Modules\DISCORD\DiscordChannel,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};
use Nadybot\Modules\TRACKER_MODULE\TrackerController;
use Psr\Log\LoggerInterface;
use Throwable;

#[NCA\MigrationOrder(20210826135816)]
class MigrateToRoutes implements SchemaMigration {
	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private DiscordAPIClient $discordAPIClient;

	#[NCA\Inject]
	private TrackerController $trackerController;

	#[NCA\Inject]
	private MessageHub $messageHub;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTES;
		$showWhere = $this->getSetting($db, "show_tracker_events");
		if (!isset($showWhere)) {
			if (strlen($this->config->general->orgName)) {
				$showWhere = 2;
			} else {
				$showWhere = 1;
			}
		} else {
			$showWhere = (int)$showWhere->value;
		}
		$map = [
			1 => Source::PRIV . "({$this->config->main->character})",
			2 => Source::ORG,
		];
		foreach ($map as $flag => $dest) {
			if ($showWhere & $flag) {
				$route = [
					"source" => $this->trackerController->getChannelName(),
					"destination" => $dest,
					"two_way" => false,
				];
				$db->table($table)->insert($route);
			}
		}
		$notifyChannel = $this->getSetting($db, "discord_notify_channel");
		if (!isset($notifyChannel) || !isset($notifyChannel->value) || $notifyChannel->value === "off") {
			return;
		}
		if ($showWhere & 4) {
			try {
				$channel = $this->discordAPIClient->getChannel($notifyChannel->value);
				$this->migrateChannelToRoute($channel, $db);
			} catch (Throwable) {
			}
		}
	}

	public function migrateChannelToRoute(DiscordChannel $channel, DB $db): void {
		$route = new Route();
		$route->source = $this->trackerController->getChannelName();
		$route->destination = Source::DISCORD_PRIV . "({$channel->name})";
		$route->id = $db->insert(MessageHub::DB_TABLE_ROUTES, $route);
		try {
			$msgRoute = $this->messageHub->createMessageRoute($route);
			$this->messageHub->addRoute($msgRoute);
		} catch (Exception $e) {
			// Ain't nothing we can do, errors will be given on next restart
		}
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}
}
