<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	Instance,
	LoggerWrapper,
	Registry,
	SettingManager,
};
use Nadybot\Modules\RELAY_MODULE\{
	Layer\HighwayPublic,
	RelayProtocol\BossTimers,
	Transport\Websocket,
};

/**
 * This class is the interface to the public highway channels
 * @author Nadyita
 */
#[NCA\Instance]
class GlobalEventsController extends Instance {
		#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public RelayController $relayController;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	public Relay $relay;

	#[NCA\Event(
		name: "connect",
		description: "Connect to the global event feed"
	)]
	public function connectToHighway(): void {
		$relay = new Relay("global_events");
		Registry::injectDependencies($relay);
		$relay->registerAsEmitter = false;
		$relay->registerAsReceiver = false;
		$transportLayer = new Websocket("wss://ws.nadybot.org");
		Registry::injectDependencies($transportLayer);
		$highwayLayer = new HighwayPublic(["boss_timers"]);
		Registry::injectDependencies($highwayLayer);
		$protocolLayer = new BossTimers();
		Registry::injectDependencies($protocolLayer);
		$relay->setStack($transportLayer, $protocolLayer, $highwayLayer);
		$this->relay = $relay;
		$relay->init(function(): void {
			$this->logger->notice("Global timer events connected.");
		});
	}
}
