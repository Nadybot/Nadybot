<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE\Migrations;

use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	ConfigFile,
	Modules\DISCORD\DiscordChannel,
	DB,
	DBSchema\Route,
	DBSchema\Setting,
	LoggerWrapper,
	MessageHub,
	Modules\DISCORD\DiscordAPIClient,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};
use Nadybot\Modules\TOWER_MODULE\TowerController;

class MigrateToRoutes implements SchemaMigration {
	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public DiscordAPIClient $discordAPIClient;

	#[NCA\Inject]
	public TowerController $towerController;

	#[NCA\Inject]
	public MessageHub $messageHub;

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$towerColor = $this->getSetting($db, "tower_spam_color");
		if (isset($towerColor)
			&& preg_match("/#([0-9A-F]{6})/", $towerColor->value??"", $matches)
		) {
			$towerColor = $matches[1];
		} else {
			$towerColor = "F06AED";
		}
		$hopColor = [
			"hop" => Source::SYSTEM . "(tower-*)",
			"tag_color" => $towerColor,
			"text_color" => null,
		];
		$db->table(MessageHub::DB_TABLE_COLORS)->insert($hopColor);

		$hopFormat = [
			"hop" => Source::SYSTEM . "(tower-*)",
			"format" => "TOWER",
			"render" => true,
		];
		$db->table(Source::DB_TABLE)->insert($hopFormat);

		$this->messageHub->loadTagFormat();

		$table = MessageHub::DB_TABLE_ROUTES;
		$showWhere = $this->getSetting($db, "tower_spam_target");
		if (!isset($showWhere)) {
			if (strlen($this->config->orgName)) {
				$showWhere = 2;
			} else {
				$showWhere = 1;
			}
		} else {
			$showWhere = (int)$showWhere->value;
		}
		$map = [
			1 => Source::PRIV . "({$this->config->name})",
			2 => Source::ORG,
		];
		foreach ($map as $flag => $dest) {
			if (($showWhere & $flag) === 0) {
				continue;
			}
			foreach (["tower-attack", "tower-victory"] as $type) {
				$route = [
					"source" => Source::SYSTEM . "({$type})",
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
		$this->discordAPIClient->getChannel(
			$notifyChannel->value,
			[$this, "migrateChannelToRoute"],
			$db,
			($showWhere & 4) > 0
		);
	}

	public function migrateChannelToRoute(DiscordChannel $channel, DB $db, bool $defaults): void {
		$types = [];
		if ($defaults) {
			$types = ["tower-attack", "tower-victory"];
		}
		$showWhere = $this->getSetting($db, "discord_notify_org_attacks");
		if (isset($showWhere) && $showWhere->value !== "off") {
			$types []= "tower-attack-own";
		}
		foreach ($types as $type) {
			$route = new Route();
			$route->source = Source::SYSTEM . "({$type})";
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
}
