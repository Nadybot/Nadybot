<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Migrations;

use Nadybot\Core\{
	Attributes as NCA,
	ConfigFile,
	DB,
	DBSchema\Route,
	DBSchema\RouteModifier,
	DBSchema\RouteModifierArgument,
	DBSchema\Setting,
	EventManager,
	LoggerWrapper,
	MessageHub,
	Modules\CONFIG\ConfigController,
	Nadybot,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};
use Nadybot\Modules\RELAY_MODULE\{
	RelayConfig,
	RelayController,
	RelayLayer,
	RelayLayerArgument,
};

class MigrateToRelayTable implements SchemaMigration {
	#[NCA\Inject]
	public RelayController $relayController;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public ConfigController $configController;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	protected string $prefix = "";

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$relay = $this->migrateRelay($db);
		if (isset($relay)) {
			$this->configController->toggleEvent("connect", "relaycontroller.loadRelays", true);
			$this->addRouting($db, $relay);
		}
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		if (preg_match("/^(bot|relay)/", $name)) {
			$name = "{$this->prefix}{$name}";
		}
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	protected function relayLogon(DB $db): bool {
		if ($this->prefix === "a") {
			return false;
		}
		$relayLogon = $db->table(EventManager::DB_TABLE)
			->where("module", "RELAY_MODULE")
			->where("status", "1")
			->whereIn("type", ["logon", "logoff", "joinpriv", "leavepriv"])
			->exists();
		return $relayLogon;
	}

	protected function addMod(DB $db, int $routeId, string $modifier): int {
		$mod = new RouteModifier();
		$mod->route_id = $routeId;
		$mod->modifier = $modifier;
		return $db->insert($this->messageHub::DB_TABLE_ROUTE_MODIFIER, $mod);
	}

	/** @param array<string,mixed> $kv */
	protected function addArgs(DB $db, int $modId, array $kv): void {
		foreach ($kv as $name => $value) {
			$arg = new RouteModifierArgument();
			$arg->route_modifier_id = $modId;
			$arg->name = $name;
			$arg->value = (string)$value;
			$db->insert($this->messageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT, $arg);
		}
	}

	protected function migrateRelay(DB $db): ?RelayConfig {
		$relayType = $this->getSetting($db, "relaytype");
		$relayBot = $this->getSetting($db, "relaybot");
		if (!isset($relayType) || !isset($relayBot) || $relayBot->value === 'Off') {
			if ($this->prefix !== "") {
				return null;
			}
			$this->prefix = "a";
			return $this->migrateRelay($db);
		}
		if ($this->prefix === "a") {
			$abbr = $this->getSetting($db, "relay_guild_abbreviation");
			if (isset($abbr, $abbr->value)   && $abbr->value !== "none") {
				$this->settingManager->save("relay_guild_abbreviation", $abbr->value);
			}
		}
		$relay = new RelayConfig();
		$relay->name = $relayBot->value ?? "Relay";
		$relay->id = $db->insert($this->relayController::DB_TABLE, $relay);
		$transport = new RelayLayer();
		$transport->relay_id = $relay->id;
		$transportArgs = [];
		switch ((int)$relayType->value) {
			case 1:
				$transport->layer = "tell";
				$transportArgs["bot"] = $relayBot->value;
				break;
			case 2:
				$transport->layer = "private-channel";
				$transportArgs["channel"] = $relayBot->value;
				break;
			default:
				$db->table($this->relayController::DB_TABLE)->delete($relay->id);
				return null;
		}
		$transport->id = $db->insert($this->relayController::DB_TABLE_LAYER, $transport);
		foreach ($transportArgs as $key => $value) {
			$transportArg = new RelayLayerArgument();
			$transportArg->name = $key;
			$transportArg->value = (string)$value;
			$transportArg->layer_id = $transport->id;
			$db->insert($this->relayController::DB_TABLE_ARGUMENT, $transportArg);
		}
		$protocol = new RelayLayer();
		$protocol->relay_id = $relay->id;
		$protocol->layer = ($this->prefix === "a") ? "agcr" : "grcv2";
		$db->insert($this->relayController::DB_TABLE_LAYER, $protocol);
		return $relay;
	}

	protected function addRouting(DB $db, RelayConfig $relay): void {
		$guestRelay = $this->getSetting($db, "guest_relay");
		$routesOut = [];
		$route = new Route();
		$route->source = Source::RELAY . "({$relay->name})";
		$route->destination = Source::ORG;
		$routeInOrg = $db->insert($this->messageHub::DB_TABLE_ROUTES, $route);
		$route = new Route();
		$route->source = Source::ORG;
		$route->destination = Source::RELAY . "({$relay->name})";
		$routesOut []= $db->insert($this->messageHub::DB_TABLE_ROUTES, $route);

		if (isset($guestRelay) && (int)$guestRelay->value) {
			$route = new Route();
			$route->source = Source::RELAY . "({$relay->name})";
			$route->destination = Source::PRIV . "({$this->config->name})";
			$routeInPriv = $db->insert($this->messageHub::DB_TABLE_ROUTES, $route);
			$route = new Route();
			$route->source = Source::PRIV . "({$this->config->name})";
			$route->destination = Source::RELAY . "({$relay->name})";
			$routesOut []= $db->insert($this->messageHub::DB_TABLE_ROUTES, $route);
		}
		$relayWhen = $this->getSetting($db, "relay_symbol_method");
		$relaySymbol = $this->getSetting($db, "relaysymbol");
		if (isset($relayWhen) && $relayWhen->value !== "0") {
			foreach ($routesOut as $routeId) {
				$symId = $this->addMod($db, $routeId, "if-has-prefix");
				$args = [
					"prefix" => $relaySymbol ? $relaySymbol->value : "@",
					"for-events" => "false",
				];
				if ($relayWhen->value === "2") {
					$args["inverse"] = "true";
				}
				$this->addArgs($db, $symId, $args);
			}
		}

		if (!$this->relayLogon($db)) {
			foreach ($routesOut as $routeId) {
				$symId = $this->addMod($db, $routeId, "remove-event");
				$args = ["type" => "online"];
				$this->addArgs($db, $symId, $args);
			}
		}

		$relayIgnore = $this->getSetting($db, "relay_ignore");
		if (isset($relayIgnore) && strlen($relayIgnore->value??"")) {
			foreach ($routesOut as $routeId) {
				foreach (explode(";", $relayIgnore->value??"") as $ignore) {
					$modId = $this->addMod($db, $routeId, "if-not-by");
					$this->addArgs($db, $modId, ["sender" => $ignore]);
				}
			}
		}

		$relayCommands = $this->getSetting($db, "bot_relay_commands");
		if (isset($relayCommands) && $relayCommands->value === "0") {
			foreach ($routesOut as $routeId) {
				$this->addMod($db, $routeId, "if-not-command");
			}
		}

		$relayFilterOut = $this->getSetting($db, "relay_filter_out");
		if (isset($relayFilterOut) && strlen($relayFilterOut->value??"")) {
			foreach ($routesOut as $routeId) {
				$modId = $this->addMod($db, $routeId, "if-matches");

				$this->addArgs($db, $modId, [
					"text" => $relayFilterOut->value,
					"regexp" => "true",
					"inverse" => "true",
				]);
			}
		}

		$relayFilterIn = $this->getSetting($db, "relay_filter_in");
		if (isset($relayFilterIn) && strlen($relayFilterIn->value??"")) {
			$modId = $this->addMod($db, $routeInOrg, "if-matches");
			$this->addArgs($db, $modId, [
				"text" => $relayFilterIn->value,
				"regexp" => "true",
				"inverse" => "true",
			]);
		}

		$relayFilterInPriv = $this->getSetting($db, "relay_filter_in_priv");
		if (isset($routeInPriv, $relayFilterInPriv)   && strlen($relayFilterInPriv->value??"")) {
			$modId = $this->addMod($db, $routeInPriv, "if-matches");
			$this->addArgs($db, $modId, [
				"text" => $relayFilterInPriv->value,
				"regexp" => "true",
				"inverse" => "true",
			]);
		}
	}
}
