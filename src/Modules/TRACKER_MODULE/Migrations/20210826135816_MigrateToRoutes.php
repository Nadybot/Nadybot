<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Exception;
use Nadybot\Core\Modules\DISCORD\DiscordChannel;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Modules\DISCORD\DiscordAPIClient;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;
use Nadybot\Core\SettingManager;
use Nadybot\Modules\TRACKER_MODULE\TrackerController;

class MigrateToRoutes implements SchemaMigration {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public DiscordAPIClient $discordAPIClient;

	#[NCA\Inject]
	public TrackerController $trackerController;

	#[NCA\Inject]
	public MessageHub $messageHub;

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = MessageHub::DB_TABLE_ROUTES;
		$showWhere = $this->getSetting($db, "show_tracker_events");
		if (!isset($showWhere)) {
			/** @psalm-suppress DocblockTypeContradiction */
			if (strlen($this->chatBot->vars['my_guild']??"")) {
				$showWhere = 2;
			} else {
				$showWhere = 1;
			}
		} else {
			$showWhere = (int)$showWhere->value;
		}
		$map = [
			1 => Source::PRIV . "({$this->chatBot->vars['name']})",
			2 => Source::ORG,
		];
		foreach ($map as $flag => $dest) {
			if ($showWhere & $flag) {
				$route = new Route();
				$route->source = $this->trackerController->getChannelName();
				$route->destination = $dest;
				$db->insert($table, $route);
			}
		}
		$notifyChannel = $this->getSetting($db, "discord_notify_channel");
		if (!isset($notifyChannel) || !isset($notifyChannel->value) || $notifyChannel->value === "off") {
			return;
		}
		if ($showWhere & 4) {
			$this->discordAPIClient->getChannel(
				$notifyChannel->value,
				[$this, "migrateChannelToRoute"],
				$db,
			);
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
}
