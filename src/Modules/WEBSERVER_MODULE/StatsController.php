<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	ConfigFile,
	ModuleInstance,
	Registry,
	SettingManager,
	Util,
};
use Nadybot\Modules\WEBSERVER_MODULE\Collector\{
	AoDataInbound,
	AoDataOutbound,
	AoPackets,
	BuddylistOffline,
	BuddylistOnline,
	BuddylistSize,
	CmdStats,
	MemoryPeakUsage,
	MemoryRealUsage,
	MemoryUsage,
};
use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\ValueProvider;

#[NCA\Instance]
class StatsController extends ModuleInstance {
	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public Util $util;

	/** @var array<string,Dataset> */
	private array $dataSets = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'prometheus_enabled',
			description: 'Enable Prometheus endpoint at /metrics',
			mode: 'edit',
			type: 'options',
			value: '1',
			options: 'true;false',
			intoptions: '1;0',
			accessLevel: 'admin'
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'prometheus_auth_token',
			description: 'Auth token for Prometheus endpoint',
			mode: 'noedit',
			type: 'text',
			value: $this->util->getPassword(16),
			accessLevel: 'admin'
		);
		$collectors = [
			"ao_data" => [new AoDataInbound(), new AoDataOutbound],
			"buddylist" => [new BuddylistSize(), new BuddylistOnline(), new BuddylistOffline()],
			"memory" => [new MemoryRealUsage(), new MemoryUsage(), new MemoryPeakUsage()],
		];
		foreach ($collectors as $name => $objs) {
			foreach ($objs as $obj) {
				Registry::injectDependencies($obj);
				$this->registerProvider($obj, $name);
			}
		}
		$aoPackets = new Aopackets("ao_packets");
		Registry::injectDependencies($aoPackets);
		$this->registerDataset($aoPackets, "ao_packets");
		$this->registerDataset(new CmdStats("cmd_times"), "cmd_times");
		$this->settingManager->registerChangeListener("prometheus_enabled", [$this, "changePrometheusStatus"]);
	}

	public function changePrometheusStatus(string $settingName, string $oldValue, string $newValue, mixed $data): void {
		if ($oldValue === $newValue) {
			return;
		}
		if ($newValue === "1") {
			$this->settingManager->save("prometheus_auth_token", $this->util->getPassword(16));
		} else {
			$this->settingManager->save("prometheus_auth_token", "<none>");
		}
	}

	public function registerDataset(Dataset $set, string $name): void {
		$this->dataSets[$name] = $set;
	}

	public function registerProvider(ValueProvider $provider, string $name): void {
		if (!isset($this->dataSets[$name])) {
			$this->registerDataset(new Dataset($name, ...array_keys($provider->getTags())), $name);
		}
		$this->dataSets[$name]->registerProvider($provider);
	}

	/**
	 * Query prometheus-formatted statistics
	 */
	#[
		NCA\HttpGet("/metrics"),
		NCA\HttpOwnAuth,
	]
	public function getMetricsEndpoint(Request $request, HttpProtocolWrapper $server): void {
		if (!$this->settingManager->getBool('prometheus_enabled')) {
			$server->httpError(new Response(
				Response::NOT_FOUND,
			));
			return;
		}
		$authHeader = $request->headers["authorization"] ?? null;
		if (
			!isset($authHeader)
			|| !preg_match("/^([bB]earer +)?" . preg_quote($this->settingManager->getString('prometheus_auth_token') ?? '') . '$/', $authHeader)
		) {
			$server->httpError(new Response(
				Response::UNAUTHORIZED,
				["WWW-Authenticate" => "Bearer realm=\"{$this->config->name}\""],
			));
			return;
		}
		$server->sendResponse(new Response(
			Response::OK,
			['Content-type' => "text/plain; version=0.0.4"],
			$this->getMetricsData()
		), true);
	}

	public function getMetricsData(): string {
		$lines = [];
		foreach ($this->dataSets as $name => $dataset) {
			$values = $dataset->getValues();
			if (count($values) > 0) {
				$lines []= join("\n", $values);
			}
		}
		return join("\n\n", $lines);
	}
}
