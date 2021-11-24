<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Registry;
use Nadybot\Core\SettingManager;
use Nadybot\Modules\RELAY_MODULE\Layer\HighwayPublic;
use Nadybot\Modules\RELAY_MODULE\RelayProtocol\BossTimers;
use Nadybot\Modules\RELAY_MODULE\Transport\Websocket;

/**
 * This class is the interface to the public highway channels
 *
 * @author Nadyita
 * @Instance
 */
class GlobalEventsController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public RelayController $relayController;

	/** @Logger */
	public LoggerWrapper $logger;

	public Relay $relay;

	/**
	 * @Event("connect")
	 * @Description("Connect to the global event feed")
	 */
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
			$this->logger->log("INFO", "Global timer events connected.");
		});
	}
}
