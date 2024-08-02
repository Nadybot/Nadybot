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

		$route = [
			'source' => Source::PRIV . "({$this->config->main->character})",
			'destination' => Source::ORG,
			'two_way' => $unfiltered,
		];
		$route['id'] = $db->table(Route::getTable())->insertGetId($route);
		$this->addCommandFilter($db, $relayCommands, $route['id']);
		if ($unfiltered) {
			return;
		}
		$route = [
			'source' => Source::ORG,
			'destination' => Source::PRIV . "({$this->config->main->character})",
			'two_way' => false,
		];
		$route['id'] = $db->table(Route::getTable())->insertGetId($route);
		$this->addCommandFilter($db, $relayCommands, $route['id']);

		if (isset($ignoreSenders) && strlen($ignoreSenders->value??'') > 0) {
			$toIgnore = explode(',', $ignoreSenders->value??'');
			$this->ignoreSenders($db, $route['id'], ...$toIgnore);
		}

		if (isset($relayFilter) && strlen($relayFilter->value??'') > 0) {
			$this->addRegExpFilter($db, $route['id'], $relayFilter->value??'');
		}
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(Setting::getTable())
			->where('name', $name)
			->asObj(Setting::class)
			->first();
	}

	protected function addCommandFilter(DB $db, ?Setting $relayCommands, int $routeId): void {
		if (!isset($relayCommands) || $relayCommands->value === '1') {
			return;
		}
		$mod = [
			'modifier' => 'if-not-command',
			'route_id' => $routeId,
		];
		$db->table(RouteModifier::getTable())->insert($mod);
	}

	protected function ignoreSenders(DB $db, int $routeId, string ...$senders): void {
		foreach ($senders as $sender) {
			$mod = [
				'modifier' => 'if-not-by',
				'route_id' => $routeId,
			];
			$mod['id'] = $db->table(RouteModifier::getTable())->insertGetId($mod);

			$arg = [
				'name' => 'sender',
				'value' => $sender,
				'route_modifier_id' => $mod['id'],
			];

			$arg['id'] = $db->table(RouteModifierArgument::getTable())->insertGetId($arg);
		}
	}

	protected function addRegExpFilter(DB $db, int $routeId, string $filter): void {
		$mod = [
			'modifier' => 'if-matches',
			'route_id' => $routeId,
		];
		$mod['id'] = $db->table(RouteModifier::getTable())->insertGetId($mod);

		$db->table(RouteModifierArgument::getTable())->insert([
			'name' => 'text',
			'value' => $filter,
			'route_modifier_id' => $mod['id'],
		]);

		$db->table(RouteModifierArgument::getTable())->insert([
			'name' => 'regexp',
			'value' => 'true',
			'route_modifier_id' => $mod['id'],
		]);
	}
}
