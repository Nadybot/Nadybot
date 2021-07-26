<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;

/**
 * @Instance
 */
class MessageHub {
	public const DB_TABLE_ROUTES = "routes_<myname>";

	/** @var array<string,MessageReceiver> */
	protected array $receivers = [];

	/** @var array<string,MessageEmitter> */
	protected array $emitters = [];

	/** @var array<string,array<string,MessageRoute>> */
	protected array $routes = [];

	/** @Inject */
	public Text $text;

	/** @Inject */
	public BuddylistManager $buddyListManager;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * Register an object for handling messages for a channel
	 */
	public function registerMessageReceiver(MessageReceiver $messageReceiver): self {
		$channel = $messageReceiver->getChannelName();
		$this->receivers[strtolower($channel)] = $messageReceiver;
		$this->logger->log('INFO', "Registered new event receiver for {$channel}");
		return $this;
	}

	/**
	 * Register an object as an emitter for a channel
	 */
	public function registerMessageEmitter(MessageEmitter $messageEmitter): self {
		$channel = $messageEmitter->getChannelName();
		$this->emitters[strtolower($channel)] = $messageEmitter;
		$this->logger->log('INFO', "Registered new event emitter for {$channel}");
		return $this;
	}

	/**
	 * Unregister an object for handling messages for a channel
	 */
	public function unregisterMessageReceiver(string $channel): self {
		unset($this->receivers[strtolower($channel)]);
		$this->logger->log('INFO', "Removed event receiver for {$channel}");
		return $this;
	}

	/**
	 * Unregister an object as an emitter for a channel
	 */
	public function unregisterMessageEmitter(string $channel): self {
		unset($this->emitters[strtolower($channel)]);
		$this->logger->log('INFO', "Removed event emitter for {$channel}");
		return $this;
	}

	/**
	 * Determine the most specific receiver for a channel
	 */
	public function getReceiver(string $channel): ?MessageReceiver {
		$channel = strtolower($channel);
		if (isset($this->receivers[$channel])) {
			return $this->receivers[$channel];
		}
		foreach ($this->receivers as $receiverChannel => $receiver) {
			if (fnmatch($receiverChannel, $channel)) {
				return $receiver;
			}
		}
		return null;
	}

	/**
	 * Get a list of all message receivers
	 * @return array<string,MessageReceiver>
	 */
	public function getReceivers(): array {
		return $this->receivers;
	}

	/**
	 * Get a list of all message emitters
	 * @return array<string,MessageEmitter>
	 */
	public function getEmitters(): array {
		return $this->emitters;
	}

	/**
	 * Submit an event to be routed according to the configured connections
	 */
	public function handle(RoutableEvent $event): void {
		$this->logger->log('INFO', "Received event to route");
		$path = $event->getPath();
		if (empty($path)) {
			$this->logger->log('INFO', "Discarding event without path");
			return;
		}
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			$this->logger->log('INFO', "Discarding non-message event");
			return;
		}
		$type = strtolower("{$path[0]->type}({$path[0]->name})");
		$this->logger->log('INFO', "Trying to route {$type}");
		// var_dump($event);
		// $this->logger->log('INFO', json_encode($event));
		foreach ($this->routes as $source => $dest) {
			if (!strpos($source, '(')) {
				$source .= '(*)';
			}
			if (!fnmatch(strtolower($source), $type)) {
				continue;
			}
			foreach ($dest as $destName => $route) {
				$receiver = $this->getReceiver($destName);
				if (!isset($receiver)) {
					$this->logger->log('INFO', "No receiver registered for {$destName}");
					continue;
				}
				$modifiedEvent = $route->modifyEvent($event);
				if (!isset($modifiedEvent)) {
					$this->logger->log('INFO', "Event filtered away for {$destName}");
					continue;
				}
				$this->logger->log('INFO', "Event routed to {$destName}");
				$modifiedEvent->setData($this->renderPath($modifiedEvent).$modifiedEvent->getData());
				$destination = $route->getDest();
				if (preg_match("/\((.+)\)$/", $destination, $matches)) {
					$destination = $matches[1];
				}
				$receiver->receive($modifiedEvent, $destination);
			}
		}
	}

	/** Get the text to prepend to a message to denote its source path */
	public function renderPath(RoutableEvent $event): string {
		$hops = [];
		$lastHop = null;
		foreach ($event->getPath() as $hop) {
			$renderedHop = $this->renderSource($hop, $lastHop);
			if (isset($renderedHop)) {
				$hops []= $renderedHop;
			}
			$lastHop = $hop;
		}
		$charLink = "";
		$hopText = "";
		$char = $event->getCharacter();
		if (isset($char)) {
			$charLink = $this->text->makeUserlink($char->name) . ": ";
		}
		if (!empty($hops)) {
			$hopText = join(" ", $hops) . " ";
		}
		return $hopText.$charLink;
	}

	public function renderSource(Source $source, ?Source $lastHop): ?string {
		$name = $source->render($lastHop);
		if (!isset($name)) {
			return null;
		}
		return "[{$name}]";
	}

	public function getCharacter(string $dest): ?string {
		$regExp = "/" . preg_quote(Source::TELL, "/") . "\((.+)\)$/";
		if (!preg_match($regExp, $dest, $matches)) {
			return null;
		}
		return $matches[1];
	}

	/**
	 * Add a route to the routing table, either adding or replacing
	 */
	public function addRoute(MessageRoute $route): void {
		$source = $route->getSource();
		$dest = $route->getDest();

		$this->routes[$source] ??= [];
		$this->routes[$source][$dest] = $route;
		$char = $this->getCharacter($dest);
		if (isset($char)) {
			$this->buddyListManager->add($char, "msg_hub");
		}
		if (!$route->getTwoWay()) {
			return;
		}
		$this->routes[$dest] ??= [];
		$this->routes[$dest][$source] = $route;
		$char = $this->getCharacter($source);
		if (isset($char)) {
			$this->buddyListManager->add($char, "msg_hub");
		}
	}

	/**
	 * @return MessageRoute[]
	 */
	public function getRoutes(): array {
		$ids = [];
		$routes = [];
		foreach ($this->routes as $source => $destData) {
			foreach ($destData as $dest => $route) {
				if (isset($ids[$route->getID()])) {
					continue;
				}
				$routes []= $route;
				$ids[$route->getID()] = true;
			}
		}
		return $routes;
	}

	public function deleteRouteID(int $id): ?MessageRoute {
		foreach ($this->routes as $source => $destData) {
			foreach ($destData as $dest => $route) {
				if ($route->getID() !== $id) {
					continue;
				}
				$result = $this->routes[$source][$dest];
				unset($this->routes[$source][$dest]);
				$char = $this->getCharacter($dest);
				if (isset($char)) {
					$this->buddyListManager->remove($char, "msg_hub");
				}
				if ($result->getTwoWay()) {
					$char = $this->getCharacter($source);
					if (isset($char)) {
						$this->buddyListManager->remove($char, "msg_hub");
					}
				}
				return $result;
			}
		}
		return null;
	}
}
