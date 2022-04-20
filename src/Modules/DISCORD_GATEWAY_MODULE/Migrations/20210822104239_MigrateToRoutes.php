<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Migrations;

use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	ConfigFile,
	DB,
	DBSchema\Route,
	DBSchema\RouteModifier,
	DBSchema\Setting,
	LoggerWrapper,
	MessageHub,
	Modules\DISCORD\DiscordChannel,
	Modules\DISCORD\DiscordController,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};

class MigrateToRoutes implements SchemaMigration {
	#[NCA\Inject]
	public DiscordController $discordController;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public MessageHub $messageHub;

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	protected function getColor(DB $db, string ...$names): string {
		foreach ($names as $name) {
			$setting = $this->getSetting($db, $name);
			if (!isset($setting) || $setting->value !== "<font color='#C3C3C3'>") {
				continue;
			}
			if (!preg_match("/#([A-F0-9]{6})/i", $setting->value, $matches)) {
				continue;
			}
			return $matches[1];
		}
		return "C3C3C3";
	}

	protected function saveColor(DB $db, string $hop, string $tag, string $text): void {
		$spec = [
			"hop" => $hop,
			"tag_color" => $tag,
			"text_color" => $text,
		];
		$db->table(MessageHub::DB_TABLE_COLORS)->insert($spec);
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		// throw new Exception("Hollera!");
		$tagColor = $this->getColor($db, "discord_color_channel");
		$textColor = $this->getColor($db, "discord_color_guild", "discord_color_priv");
		$this->saveColor($db, Source::DISCORD_PRIV, $tagColor, $textColor);

		$relayChannel = $this->getSetting($db, "discord_relay_channel");
		$relayWhat = $this->getSetting($db, "discord_relay");
		if (!isset($relayChannel) || !isset($relayChannel->value) || $relayChannel->value === "off") {
			return;
		}
		if (!isset($relayWhat) || $relayWhat->value === "0") {
			return;
		}
		$relayCommands = $this->getSetting($db, "discord_relay_commands");
		$this->discordController->discordAPIClient->getChannel(
			$relayChannel->value,
			[$this, "migrateChannelToRoute"],
			$db,
			$relayWhat,
			$relayCommands,
		);
	}

	public function migrateChannelToRoute(DiscordChannel $channel, DB $db, Setting $relayWhat, Setting $relayCommands): void {
		if ((int)$relayWhat->value & 2) {
			$this->addRoute(
				$db,
				Source::DISCORD_PRIV . "({$channel->name})",
				Source::ORG,
				$relayCommands->value === "1"
			);
		}
		if ((int)$relayWhat->value & 1) {
			$this->addRoute(
				$db,
				Source::DISCORD_PRIV . "({$channel->name})",
				Source::PRIV . "({$this->config->name})",
				$relayCommands->value === "1"
			);
		}
	}

	protected function addRoute(DB $db, string $from, string $to, bool $relayCommands): void {
		$route = new Route();
		$route->source = $from;
		$route->destination = $to;
		$route->two_way = true;
		$route->id = $db->insert(MessageHub::DB_TABLE_ROUTES, $route);
		if (!$relayCommands) {
			$mod = new RouteModifier();
			$mod->route_id = $route->id;
			$mod->modifier = "if-not-command";
			$mod->id = $db->insert(MessageHub::DB_TABLE_ROUTE_MODIFIER, $mod);
			$route->modifiers []= $mod;
		}

		try {
			$msgRoute = $this->messageHub->createMessageRoute($route);
			$this->messageHub->addRoute($msgRoute);
		} catch (Exception $e) {
			// Ain't nothing we can do, errors will be given on next restart
		}
	}
}
