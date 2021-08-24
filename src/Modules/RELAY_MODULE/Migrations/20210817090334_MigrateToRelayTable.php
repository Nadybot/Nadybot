<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Migrations;

use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\DBSchema\RouteModifier;
use Nadybot\Core\DBSchema\RouteModifierArgument;
use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;
use Nadybot\Core\SettingManager;
use Nadybot\Modules\RELAY_MODULE\RelayConfig;
use Nadybot\Modules\RELAY_MODULE\RelayController;
use Nadybot\Modules\RELAY_MODULE\RelayLayer;
use Nadybot\Modules\RELAY_MODULE\RelayLayerArgument;

class MigrateToRelayTable implements SchemaMigration {
	/** @Inject */
	public RelayController $relayController;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Nadybot $chatBot;

	protected string $prefix = "";

	protected function getSetting(DB $db, string $name): ?Setting {
		if (preg_match("/^(bot|relay)/", $name)) {
			$name = "{$this->prefix}{$name}";
		}
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	protected function addMod(DB $db, int $routeId, string $modifier): int {
		$mod = new RouteModifier();
		$mod->route_id = $routeId;
		$mod->modifier = $modifier;
		return $db->insert($this->messageHub::DB_TABLE_ROUTE_MODIFIER, $mod);
	}

	protected function addArgs(DB $db, int $modId, array $kv): void {
		foreach ($kv as $name => $value) {
			$arg = new RouteModifierArgument();
			$arg->route_modifier_id = $modId;
			$arg->name = $name;
			$arg->value = (string)$value;
			$db->insert($this->messageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT, $arg);
		}
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$relay = $this->migrateRelay($db);
		if (isset($relay)) {
			$this->addRouting($db, $relay);
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
			return$this->migrateRelay($db);
		}
		if ($this->prefix === "a") {
			$abbr = $this->getSetting($db, "relay_guild_abbreviation");
			if (isset($abbr) && $abbr->value !== "none") {
				$this->settingManager->save("relay_guild_abbreviation", $abbr->value);
			}
		}
		$relay = new RelayConfig();
		$relay->name = $relayBot->value;
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
			case 3:
				$transport->layer = "amqp";
				$transportArgs["server"] = $this->chatBot->vars["amqp_server"] ?? "127.0.0.1";
				$transportArgs["port"] = $this->chatBot->vars["amqp_port"] ?? "5672";
				$transportArgs["vhost"] = $this->chatBot->vars["amqp_vhost"] ?? "/";
				$transportArgs["user"] = $this->chatBot->vars["amqp_user"] ?? "guest";
				$transportArgs["password"] = $this->chatBot->vars["amqp_password"] ?? "guest";
				$transportArgs["exchange"] = $relayBot->value;
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
			$route->destination = Source::PRIV . "({$this->chatBot->vars['name']})";
			$routeInPriv = $db->insert($this->messageHub::DB_TABLE_ROUTES, $route);
			$route = new Route();
			$route->source = Source::PRIV . "({$this->chatBot->vars['name']})";
			$route->destination = Source::RELAY . "({$relay->name})";
			$routesOut []= $db->insert($this->messageHub::DB_TABLE_ROUTES, $route);
		}
		$relayWhen = $this->getSetting($db, "relay_symbol_method");
		$relaySymbol = $this->getSetting($db, "relaysymbol");
		if (isset($relayWhen) && $relayWhen->value !== "0") {
			foreach ($routesOut as $routeId) {
				$symId = $this->addMod($db, $routeId, "if-has-prefix");
				$args = ["prefix" => $relaySymbol ? $relaySymbol->value : "@"];
				if ($relayWhen->value === "2") {
					$args["inverse"] = "true";
				}
				$this->addArgs($db, $symId, $args);
			}
		}

		$relayIgnore = $this->getSetting($db, "relay_ignore");
		if (isset($relayIgnore) && strlen($relayIgnore->value)) {
			foreach ($routesOut as $routeId) {
				foreach (explode(";", $relayIgnore->value) as $ignore) {
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
		if (isset($relayFilterOut) && strlen($relayFilterOut->value)) {
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
		if (isset($relayFilterIn) && strlen($relayFilterIn->value)) {
			$modId = $this->addMod($db, $routeInOrg, "if-matches");
			$this->addArgs($db, $modId, [
				"text" => $relayFilterIn->value,
				"regexp" => "true",
				"inverse" => "true",
			]);
		}

		$relayFilterInPriv = $this->getSetting($db, "relay_filter_in_priv");
		if (isset($routeInPriv) && isset($relayFilterInPriv) && strlen($relayFilterInPriv->value)) {
			$modId = $this->addMod($db, $routeInPriv, "if-matches");
			$this->addArgs($db, $modId, [
				"text" => $relayFilterInPriv->value,
				"regexp" => "true",
				"inverse" => "true",
			]);
		}
	}
}
