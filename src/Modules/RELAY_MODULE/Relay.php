<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\MessageHub;
use Nadybot\Modules\RELAY_MODULE\RelayProtocol\RelayProtocolInterface;
use Nadybot\Modules\RELAY_MODULE\Transport\TransportInterface;

class Relay {
	public const RELAY_INCOMING = 1;
	public const RELAY_OUTGOING = 2;

	/** @Inject */
	public MessageHub $messageHub;

	protected string $name;
	/** @var RelayStackMember[] */
	protected array $stack = [];
	protected array $events = [];
	protected TransportInterface $transport;
	protected RelayProtocolInterface $relayProtocol;

	public function __construct(string $name) {
		$this->name = $name;
	}

	/**
	 * Set the stack members that make up the stack
	 */
	public function setStack(
		 TransportInterface $transport,
		 RelayProtocolInterface $relayProtocol,
		 RelayStackMember ...$stack
	) {
		$this->transport = $transport;
		$this->relayProtocol = $relayProtocol;
		$this->stack = $stack;
	}

	public function init(callable $callback, int $index=0): void {
		$elements = [$this->transport, ...$this->stack, $this->relayProtocol];
		$element = $elements[$index] ?? null;
		if (!isset($element)) {
			$callback();
			return;
		}
		$element[$index]->init(
			$elements[$index-1]??null,
			function() use ($callback, $index): void {
				$this->init($callback, $index+1);
			}
		);
	}

	public function getEventConfig(string $event): int {
		return $this->events[$event] ?? 0;
	}

	/**
	 * Handle data received from the transport layer
	 */
	public function receive(string $data): void {
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
		if ($event->getType() === $event::TYPE_MESSAGE) {
			$this->eventHub->handle($event);
			return;
		}
		// Handle event
	}
}
