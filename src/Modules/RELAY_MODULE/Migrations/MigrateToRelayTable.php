<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Migrations;

use function Safe\preg_match;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DB,
	DBSchema\EventCfg,
	DBSchema\Route,
	DBSchema\RouteModifier,
	DBSchema\RouteModifierArgument,
	DBSchema\Setting,
	Modules\CONFIG\ConfigController,
	Routing\Source,
	SchemaMigration,
	SettingManager,
};
use Nadybot\Modules\RELAY_MODULE\{
	RelayConfig,
	RelayLayer,
	RelayLayerArgument,
};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_08_17_09_03_34)]
class MigrateToRelayTable implements SchemaMigration {
	protected string $prefix = '';

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private ConfigController $configController;

	#[NCA\Inject]
	private BotConfig $config;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$relay = $this->migrateRelay($db);
		if ($relay) {
			$this->configController->toggleEvent('connect', 'relaycontroller.loadRelays', true);
		}
	}

	protected function getSetting(DB $db, string $name): ?Setting {
		if (preg_match('/^(bot|relay)/', $name)) {
			$name = "{$this->prefix}{$name}";
		}
		return $db->table(Setting::getTable())
			->where('name', $name)
			->asObj(Setting::class)
			->first();
	}

	protected function relayLogon(DB $db): bool {
		if ($this->prefix === 'a') {
			return false;
		}
		$relayLogon = $db->table(EventCfg::getTable())
			->where('module', 'RELAY_MODULE')
			->where('status', '1')
			->whereIn('type', ['logon', 'logoff', 'joinpriv', 'leavepriv'])
			->exists();
		return $relayLogon;
	}

	protected function addMod(DB $db, int $routeId, string $modifier): int {
		return $db->table(RouteModifier::getTable())->insertGetId([
			'route_id' => $routeId,
			'modifier' => $modifier,
		]);
	}

	/** @param array<string,mixed> $kv */
	protected function addArgs(DB $db, int $modId, array $kv): void {
		foreach ($kv as $name => $value) {
			$db->table(RouteModifierArgument::getTable())->insert([
				'route_modifier_id' => $modId,
				'name' => $name,
				'value' => (string)$value,
			]);
		}
	}

	protected function migrateRelay(DB $db): bool {
		$relayType = $this->getSetting($db, 'relaytype');
		$relayBot = $this->getSetting($db, 'relaybot');
		if (!isset($relayType) || !isset($relayBot) || $relayBot->value === 'Off') {
			if ($this->prefix !== '') {
				return false;
			}
			$this->prefix = 'a';
			return $this->migrateRelay($db);
		}
		if ($this->prefix === 'a') {
			$abbr = $this->getSetting($db, 'relay_guild_abbreviation');
			if (isset($abbr, $abbr->value)   && $abbr->value !== 'none') {
				$this->settingManager->save('relay_guild_abbreviation', $abbr->value);
			}
		}
		$relayId = $db->table(RelayConfig::getTable())->insertGetId([
			'name' => $relayBot->value ?? 'Relay',
		]);
		$transportArgs = [];
		switch ((int)$relayType->value) {
			case 1:
				$transportLayer = 'tell';
				$transportArgs['bot'] = $relayBot->value;
				break;
			case 2:
				$transportLayer = 'private-channel';
				$transportArgs['channel'] = $relayBot->value;
				break;
			default:
				$db->table(RelayConfig::getTable())->delete($relayId);
				return false;
		}
		$transportId = $db->table(RelayLayer::getTable())->insertGetId([
			'layer' => $transportLayer,
			'relay_id' => $relayId,
		]);
		foreach ($transportArgs as $key => $value) {
			$db->table(RelayLayerArgument::getTable())->insert([
				'name' => $key,
				'value' => (string)$value,
				'layer_id' => $transportId,
			]);
		}
		$db->table(RelayLayer::getTable())->insert([
			'relay_id' => $relayId,
			'layer' => ($this->prefix === 'a') ? 'agcr' : 'grcv2',
		]);
		return true;
	}

	protected function addRouting(DB $db, RelayConfig $relay): void {
		$guestRelay = $this->getSetting($db, 'guest_relay');

		$routesOut = [];

		$routeInOrg = $db->table(Route::getTable())->insertGetId([
			'source' => Source::RELAY . "({$relay->name})",
			'destination' => Source::ORG,
		]);
		$routesOut []= $db->table(Route::getTable())->insertGetId([
			'source' => Source::ORG,
			'destination' => Source::RELAY . "({$relay->name})",
		]);

		if (isset($guestRelay) && (int)$guestRelay->value) {
			$routeInPriv = $db->table(Route::getTable())->insertGetId([
				'source' => Source::RELAY . "({$relay->name})",
				'destination' => Source::PRIV . "({$this->config->main->character})",
			]);
			$routesOut[] = $db->table(Route::getTable())->insertGetId([
				'source' => Source::PRIV . "({$this->config->main->character})",
				'destonation' => Source::RELAY . "({$relay->name})",
			]);
		}
		$relayWhen = $this->getSetting($db, 'relay_symbol_method');
		$relaySymbol = $this->getSetting($db, 'relaysymbol');
		if (isset($relayWhen) && $relayWhen->value !== '0') {
			foreach ($routesOut as $routeId) {
				$symId = $this->addMod($db, $routeId, 'if-has-prefix');
				$args = [
					'prefix' => $relaySymbol ? $relaySymbol->value : '@',
					'for-events' => 'false',
				];
				if ($relayWhen->value === '2') {
					$args['inverse'] = 'true';
				}
				$this->addArgs($db, $symId, $args);
			}
		}

		if (!$this->relayLogon($db)) {
			foreach ($routesOut as $routeId) {
				$symId = $this->addMod($db, $routeId, 'remove-event');
				$args = ['type' => 'online'];
				$this->addArgs($db, $symId, $args);
			}
		}

		$relayIgnore = $this->getSetting($db, 'relay_ignore');
		if (isset($relayIgnore) && strlen($relayIgnore->value??'')) {
			foreach ($routesOut as $routeId) {
				foreach (explode(';', $relayIgnore->value??'') as $ignore) {
					$modId = $this->addMod($db, $routeId, 'if-not-by');
					$this->addArgs($db, $modId, ['sender' => $ignore]);
				}
			}
		}

		$relayCommands = $this->getSetting($db, 'bot_relay_commands');
		if (isset($relayCommands) && $relayCommands->value === '0') {
			foreach ($routesOut as $routeId) {
				$this->addMod($db, $routeId, 'if-not-command');
			}
		}

		$relayFilterOut = $this->getSetting($db, 'relay_filter_out');
		if (isset($relayFilterOut) && strlen($relayFilterOut->value??'')) {
			foreach ($routesOut as $routeId) {
				$modId = $this->addMod($db, $routeId, 'if-matches');

				$this->addArgs($db, $modId, [
					'text' => $relayFilterOut->value,
					'regexp' => 'true',
					'inverse' => 'true',
				]);
			}
		}

		$relayFilterIn = $this->getSetting($db, 'relay_filter_in');
		if (isset($relayFilterIn) && strlen($relayFilterIn->value??'')) {
			$modId = $this->addMod($db, $routeInOrg, 'if-matches');
			$this->addArgs($db, $modId, [
				'text' => $relayFilterIn->value,
				'regexp' => 'true',
				'inverse' => 'true',
			]);
		}

		$relayFilterInPriv = $this->getSetting($db, 'relay_filter_in_priv');
		if (isset($routeInPriv, $relayFilterInPriv)   && strlen($relayFilterInPriv->value??'')) {
			$modId = $this->addMod($db, $routeInPriv, 'if-matches');
			$this->addArgs($db, $modId, [
				'text' => $relayFilterInPriv->value,
				'regexp' => 'true',
				'inverse' => 'true',
			]);
		}
	}
}
