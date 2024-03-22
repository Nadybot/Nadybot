<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Migrations;

use Exception;
use Nadybot\Core\Modules\DISCORD\DiscordAPIClient;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DB,
	DBSchema\Route,
	DBSchema\RouteModifier,
	DBSchema\Setting,
	MessageHub,
	Modules\DISCORD\DiscordChannel,
	Routing\Source,
	Safe,
	SchemaMigration,
	SettingManager,
};

use Psr\Log\LoggerInterface;
use Throwable;

#[NCA\Migration(order: 20_210_822_104_239)]
class MigrateToRoutes implements SchemaMigration {
	#[NCA\Inject]
	private DiscordAPIClient $discordApiClient;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private MessageHub $messageHub;

	public function migrate(LoggerInterface $logger, DB $db): void {
		// throw new Exception("Hollera!");
		$tagColor = $this->getColor($db, 'discord_color_channel');
		$textColor = $this->getColor($db, 'discord_color_guild', 'discord_color_priv');
		$this->saveColor($db, Source::DISCORD_PRIV, $tagColor, $textColor);

		$relayChannel = $this->getSetting($db, 'discord_relay_channel');
		$relayWhat = $this->getSetting($db, 'discord_relay');
		if (!isset($relayChannel) || !isset($relayChannel->value) || $relayChannel->value === 'off') {
			return;
		}
		if (!isset($relayWhat) || $relayWhat->value === '0') {
			return;
		}
		$relayCommands = $this->getSetting($db, 'discord_relay_commands');
		if (isset($relayCommands)) {
			$relayCommands = $relayCommands->value === '1';
		} else {
			$relayCommands = false;
		}
		try {
			$channel = $this->discordApiClient->getChannel($relayChannel->value);
			$this->migrateChannelToRoute($channel, $db, $relayWhat, $relayCommands);
		} catch (Throwable) {
		}
	}

	public function migrateChannelToRoute(DiscordChannel $channel, DB $db, Setting $relayWhat, bool $relayCommands): void {
		if ((int)$relayWhat->value & 2) {
			$this->addRoute(
				$db,
				Source::DISCORD_PRIV . "({$channel->name})",
				Source::ORG,
				$relayCommands
			);
		}
		if ((int)$relayWhat->value & 1) {
			$this->addRoute(
				$db,
				Source::DISCORD_PRIV . "({$channel->name})",
				Source::PRIV . "({$this->config->main->character})",
				$relayCommands
			);
		}
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where('name', $name)
			->asObj(Setting::class)
			->first();
	}

	protected function getColor(DB $db, string ...$names): string {
		foreach ($names as $name) {
			$setting = $this->getSetting($db, $name);
			if (!isset($setting) || $setting->value !== "<font color='#C3C3C3'>") {
				continue;
			}
			if (!count($matches = Safe::pregMatch('/#([A-F0-9]{6})/i', $setting->value))) {
				continue;
			}
			return $matches[1];
		}
		return 'C3C3C3';
	}

	protected function saveColor(DB $db, string $hop, string $tag, string $text): void {
		$spec = [
			'hop' => $hop,
			'tag_color' => $tag,
			'text_color' => $text,
		];
		$db->table(MessageHub::DB_TABLE_COLORS)->insert($spec);
	}

	protected function addRoute(DB $db, string $from, string $to, bool $relayCommands): void {
		$route = new Route();
		$route->source = $from;
		$route->destination = $to;
		$route->two_way = true;
		$route->id = $db->table(MessageHub::DB_TABLE_ROUTES)->insertGetId([
			'source' => $route->source,
			'destination' => $route->destination,
			'two_way' => $route->two_way,
		]);
		if (!$relayCommands) {
			$mod = new RouteModifier();
			$mod->route_id = $route->id;
			$mod->modifier = 'if-not-command';
			$mod->id = $db->table(MessageHub::DB_TABLE_ROUTE_MODIFIER)->insertGetId([
				'route_id' => $mod->route_id,
				'modifier' => $mod->modifier,
			]);
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
