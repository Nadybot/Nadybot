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

	/** Enable Prometheus endpoint at /metrics */
	#[NCA\Setting\Boolean(accessLevel: "admin")]
	public bool $prometheusEnabled = true;

	/** Auth token for Prometheus endpoint */
	#[NCA\Setting\Text(accessLevel: "admin", mode: 'noedit')]
	public string $prometheusAuthToken = "";

	/** @var array<string,Dataset> */
	private array $dataSets = [];

	#[NCA\Setup]
	public function setup(): void {
		if ($this->prometheusAuthToken === "" &&  $this->prometheusEnabled) {
			$this->assignRandomAuthToken();
		}
		$collectors = [
			"ao_data" => [new AoDataInbound(), new AoDataOutbound()],
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
	}

	#[NCA\SettingChangeHandler('prometheus_enabled')]
	public function changePrometheusStatus(string $settingName, string $oldValue, string $newValue, mixed $data): void {
		if ($oldValue === $newValue) {
			return;
		}
		if ($newValue === "1") {
			$this->assignRandomAuthToken();
		} else {
			$this->assignEmptyAuthToken();
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
			), $request);
			return;
		}
		$authHeader = $request->headers["authorization"] ?? null;
		if (
			!isset($authHeader)
			|| !preg_match("/^([bB]earer +)?" . preg_quote($this->prometheusAuthToken, "/") . '$/', $authHeader)
		) {
			$server->httpError(new Response(
				Response::UNAUTHORIZED,
				["WWW-Authenticate" => "Bearer realm=\"{$this->config->name}\""],
			), $request);
			return;
		}
		$server->sendResponse(new Response(
			Response::OK,
			['Content-type' => "text/plain; version=0.0.4"],
			$this->getMetricsData()
		), $request, true);
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

	private function assignRandomAuthToken(): void {
		$this->settingManager->save("prometheus_auth_token", $this->util->getPassword(16));
	}

	private function assignEmptyAuthToken(): void {
		$this->settingManager->save("prometheus_auth_token", "");
	}
}
