<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use function Safe\preg_match;

use Amp\Http\HttpStatus;
use Amp\Http\Server\{Request, Response};
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
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
	/** Enable Prometheus endpoint at /metrics */
	#[NCA\Setting\Boolean(accessLevel: "admin")]
	public bool $prometheusEnabled = true;

	/** Auth token for Prometheus endpoint */
	#[NCA\Setting\Text(accessLevel: "admin", mode: 'noedit')]
	public string $prometheusAuthToken = "";
	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Util $util;

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

	/** Query prometheus-formatted statistics */
	#[
		NCA\HttpGet("/metrics"),
		NCA\HttpOwnAuth,
	]
	public function getMetricsEndpoint(Request $request): Response {
		if (!$this->settingManager->getBool('prometheus_enabled')) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		$authHeader = $request->getHeader("authorization");
		if (
			!isset($authHeader)
			|| !preg_match("/^([bB]earer +)?" . preg_quote($this->prometheusAuthToken, "/") . '$/', $authHeader)
		) {
			return new Response(
				status: HttpStatus::UNAUTHORIZED,
				headers: ["WWW-Authenticate" => "Bearer realm=\"{$this->config->main->character}\""],
			);
		}
		return new Response(
			status: HttpStatus::OK,
			headers: ['Content-Type' => "text/plain; version=0.0.4"],
			body: $this->getMetricsData(),
		);
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
