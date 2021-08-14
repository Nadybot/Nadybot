<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\MessageHub;
use Nadybot\Core\MessageReceiver;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SettingManager;
use Nadybot\Modules\RELAY_MODULE\RelayProtocol\RelayProtocolInterface;
use Nadybot\Modules\RELAY_MODULE\Transport\TransportInterface;

class Relay implements MessageReceiver {
	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	protected string $name;
	/** @var RelayLayerInterface[] */
	protected array $stack = [];
	protected array $events = [];
	protected TransportInterface $transport;
	protected RelayProtocolInterface $relayProtocol;

	protected bool $initialized = false;
	protected int $initStep = 0;

	public function __construct(string $name) {
		$this->name = $name;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getStatus(): string {
		if ($this->initialized) {
			return "<green>ready<end>";
		}
		$elements = [$this->transport, ...$this->stack, $this->relayProtocol];
		$element = $elements[$this->initStep] ?? null;
		if (!isset($element)) {
			return "<red>unknown<end>";
		}
		$class = get_class($element);
		if (($pos = strrpos($class, '\\')) !== false) {
			$class = substr($class, $pos + 1);
		}
		if ($element instanceof StatusProvider) {
			$status = $element->getStatus();
			return "<yellow>{$status}<end>";
		}
		return "<yellow>initializing {$class}<end>";
	}

	public function getChannelName(): string {
		return Source::RELAY . "({$this->name})";
	}

	/**
	 * Set the stack members that make up the stack
	 */
	public function setStack(
		 TransportInterface $transport,
		 RelayProtocolInterface $relayProtocol,
		 RelayLayerInterface ...$stack
	) {
		$this->transport = $transport;
		$this->relayProtocol = $relayProtocol;
		$this->stack = $stack;
	}

	public function deinit(?callable $callback=null, int $index=0): void {
		if ($index === 0) {
			$this->messageHub
				->unregisterMessageEmitter($this->getChannelName())
				->unregisterMessageReceiver($this->getChannelName());
		}
		/** @var RelayStackArraySenderInterface[] */
		$layers = [
			$this->relayProtocol,
			...array_reverse($this->stack),
			$this->transport
		];
		$layer = $layers[$index] ?? null;
		if (!isset($layer)) {
			if (isset($callback)) {
				$callback($this);
			}
			return;
		}
		$data = $layer->deinit(
			function() use ($callback, $index): void {
				$this->deinit($callback, $index+1);
			}
		);
		if (count($data)) {
			for ($pos = $index+1; $pos < count($layers); $pos++) {
				$data = $layers[$pos]->send($data);
			}
		}
	}

	public function init(?callable $callback=null, int $index=0): void {
		$this->initialized = false;
		$this->initStep = $index;
		/** @var RelayStackArraySenderInterface[] */
		$elements = [$this->transport, ...$this->stack, $this->relayProtocol];
		$element = $elements[$index] ?? null;
		if (!isset($element)) {
			$this->initialized = true;
			$this->messageHub
				->registerMessageEmitter($this)
				->registerMessageReceiver($this);
			if (isset($callback)) {
				$callback();
			}
			return;
		}
		$element->setRelay($this);
		$data = $element->init(
			function() use ($callback, $index): void {
				$this->init($callback, $index+1);
			}
		);
		if (count($data)) {
			for ($pos = $index-1; $pos >= 0; $pos--) {
				$data = $elements[$pos]->send($data);
			}
		}
	}

	public function getEventConfig(string $event): int {
		return $this->events[$event] ?? 0;
	}

	/**
	 * Handle data received from the transport layer
	 */
	public function receiveFromTransport(string $data): void {
		foreach ($this->stack as $stackMember) {
			$data = $stackMember->receive($data);
			if (!isset($data)) {
				return;
			}
		}
		$event = $this->relayProtocol->receive($data);
		if (!isset($event)) {
			return;
		}
		$event->prependPath(new Source(
			Source::RELAY,
			$this->name
		));
		if ($event->getType() === $event::TYPE_MESSAGE) {
			$this->messageHub->handle($event);
			return;
		}
		// Handle event
	}

	/**
	 * Make sure either the org chat or priv channel is the first element
	 * when we send data, so it can always be traced to us
	 */
	protected function prependMainHop(RoutableEvent $event): void {
		$isOrgBot = strlen($this->chatBot->vars["my_guild"]??"") > 0;
		if (!empty($event->path) && $event->path[0]->type !== Source::ORG && $isOrgBot) {
			$abbr = $this->settingManager->getString("relay_guild_abbreviation");
			$event->prependPath(new Source(
				Source::ORG,
				$this->chatBot->vars["my_guild"],
				($abbr === "none") ? null : $abbr
			));
		} elseif (!empty($event->path) && $event->path[0]->type !== Source::PRIV && !$isOrgBot) {
			$event->prependPath(new Source(
				Source::PRIV,
				$this->chatBot->char->name
			));
		}
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$this->prependMainHop($event);
		$data = $this->relayProtocol->send($event);
		for ($i = count($this->stack); $i--;) {
			$data = $this->stack[$i]->send($data);
		}
		return empty($this->transport->send($data));
	}
}
