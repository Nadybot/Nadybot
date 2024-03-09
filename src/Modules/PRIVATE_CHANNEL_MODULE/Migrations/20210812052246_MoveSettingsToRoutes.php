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
	LoggerWrapper,
	MessageHub,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};

class MoveSettingsToRoutes implements SchemaMigration {
	#[NCA\Inject]
	private BotConfig $config;

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$guestRelay = $this->getSetting($db, "guest_relay");
		if (isset($guestRelay) && $guestRelay->value !== "1") {
			return;
		}
		$relayCommands = $this->getSetting($db, "guest_relay_commands");
		$ignoreSenders = $this->getSetting($db, "guest_relay_ignore");
		$relayFilter = $this->getSetting($db, "guest_relay_filter");

		$unfiltered = (!isset($ignoreSenders) || !strlen($ignoreSenders->value??""))
			&& (!isset($relayFilter) || !strlen($relayFilter->value??""));

		$route = new Route();
		$route->source = Source::PRIV . "({$this->config->main->character})";
		$route->destination = Source::ORG;
		$route->two_way = $unfiltered;
		$route->id = $db->insert(MessageHub::DB_TABLE_ROUTES, $route);
		$this->addCommandFilter($db, $relayCommands, $route->id);
		if ($unfiltered) {
			return;
		}
		$route = new Route();
		$route->source = Source::ORG;
		$route->destination = Source::PRIV . "({$this->config->main->character})";
		$route->two_way = false;
		$route->id = $db->insert(MessageHub::DB_TABLE_ROUTES, $route);
		$this->addCommandFilter($db, $relayCommands, $route->id);

		if (isset($ignoreSenders) && strlen($ignoreSenders->value??"") > 0) {
			$toIgnore = explode(",", $ignoreSenders->value??"");
			$this->ignoreSenders($db, $route->id, ...$toIgnore);
		}

		if (isset($relayFilter) && strlen($relayFilter->value??"") > 0) {
			$this->addRegExpFilter($db, $route->id, $relayFilter->value??"");
		}
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	protected function addCommandFilter(DB $db, ?Setting $relayCommands, int $routeId): void {
		if (!isset($relayCommands) || $relayCommands->value === "1") {
			return;
		}
		$mod = new RouteModifier();
		$mod->modifier = "if-not-command";
		$mod->route_id = $routeId;
		$mod->id = $db->insert(MessageHub::DB_TABLE_ROUTE_MODIFIER, $mod);
	}

	protected function ignoreSenders(DB $db, int $routeId, string ...$senders): void {
		foreach ($senders as $sender) {
			$mod = new RouteModifier();
			$mod->modifier = "if-not-by";
			$mod->route_id = $routeId;
			$mod->id = $db->insert(MessageHub::DB_TABLE_ROUTE_MODIFIER, $mod);

			$arg = new RouteModifierArgument();
			$arg->name = "sender";
			$arg->value = $sender;
			$arg->route_modifier_id = $mod->id;

			$mod->id = $db->insert(MessageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT, $arg);
		}
	}

	protected function addRegExpFilter(DB $db, int $routeId, string $filter): void {
		$mod = new RouteModifier();
		$mod->modifier = "if-matches";
		$mod->route_id = $routeId;
		$mod->id = $db->insert(MessageHub::DB_TABLE_ROUTE_MODIFIER, $mod);

		$arg = new RouteModifierArgument();
		$arg->name = "text";
		$arg->value = $filter;
		$arg->route_modifier_id = $mod->id;

		$mod->id = $db->insert(MessageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT, $arg);

		$arg = new RouteModifierArgument();
		$arg->name = "regexp";
		$arg->value = "true";
		$arg->route_modifier_id = $mod->id;

		$mod->id = $db->insert(MessageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT, $arg);
	}
}
