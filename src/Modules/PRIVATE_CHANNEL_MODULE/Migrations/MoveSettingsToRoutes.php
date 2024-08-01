<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE\Migrations;

use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DB,
	DBSchema\Route,
	DBSchema\RouteModifier,
	DBSchema\RouteModifierArgument,
	DBSchema\Setting,
	Routing\Source,
	SchemaMigration,
};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;

#[NCA\Migration(order: 2021_08_12_05_22_46)]
class MoveSettingsToRoutes implements SchemaMigration {
	#[NCA\Inject]
	private BotConfig $config;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$guestRelay = $this->getSetting($db, 'guest_relay');
		if (isset($guestRelay) && $guestRelay->value !== '1') {
			return;
		}
		$relayCommands = $this->getSetting($db, 'guest_relay_commands');
		$ignoreSenders = $this->getSetting($db, 'guest_relay_ignore');
		$relayFilter = $this->getSetting($db, 'guest_relay_filter');

		$unfiltered = (!isset($ignoreSenders) || !strlen($ignoreSenders->value??''))
			&& (!isset($relayFilter) || !strlen($relayFilter->value??''));

		$route = new Route(
			source: Source::PRIV . "({$this->config->main->character})",
			destination: Source::ORG,
			two_way: $unfiltered,
		);
		$db->insert($route);
		$this->addCommandFilter($db, $relayCommands, $route->id);
		if ($unfiltered) {
			return;
		}
		$route = new Route(
			source: Source::ORG,
			destination: Source::PRIV . "({$this->config->main->character})",
			two_way: false,
		);
		$db->insert($route);
		$this->addCommandFilter($db, $relayCommands, $route->id);

		if (isset($ignoreSenders) && strlen($ignoreSenders->value??'') > 0) {
			$toIgnore = explode(',', $ignoreSenders->value??'');
			$this->ignoreSenders($db, $route->id, ...$toIgnore);
		}

		if (isset($relayFilter) && strlen($relayFilter->value??'') > 0) {
			$this->addRegExpFilter($db, $route->id, $relayFilter->value??'');
		}
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(Setting::getTable())
			->where('name', $name)
			->asObj(Setting::class)
			->first();
	}

	protected function addCommandFilter(DB $db, ?Setting $relayCommands, UuidInterface $routeId): void {
		if (!isset($relayCommands) || $relayCommands->value === '1') {
			return;
		}
		$mod = new RouteModifier(
			modifier: 'if-not-command',
			route_id: $routeId,
		);
		$mod->id = $db->insert($mod);
	}

	protected function ignoreSenders(DB $db, UuidInterface $routeId, string ...$senders): void {
		foreach ($senders as $sender) {
			$mod = new RouteModifier(
				modifier: 'if-not-by',
				route_id: $routeId,
			);
			$mod->id = $db->insert($mod);

			$arg = new RouteModifierArgument(
				name: 'sender',
				value: $sender,
				route_modifier_id: $mod->id,
			);

			$arg->id = $db->insert($arg);
		}
	}

	protected function addRegExpFilter(DB $db, UuidInterface $routeId, string $filter): void {
		$mod = new RouteModifier(
			modifier: 'if-matches',
			route_id: $routeId,
		);
		$mod->id = $db->insert($mod);

		$db->insert(new RouteModifierArgument(
			name: 'text',
			value: $filter,
			route_modifier_id: $mod->id,
		));

		$db->insert(new RouteModifierArgument(
			name: 'regexp',
			value: 'true',
			route_modifier_id: $mod->id,
		));
	}
}
