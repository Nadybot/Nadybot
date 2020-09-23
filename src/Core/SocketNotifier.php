<?php declare(strict_types=1);

namespace Nadybot\Core;

/**
 * The SocketNotifier class provides a way to be notified when some
 * activity happens in a socket.
 *
 * You can add instance of SocketNotifier to Nadybot's event loop with method
 * SocketManager::addSocketNotifier() and remove it with
 * SocketManager::removeSocketNotifier().
 *
 * When some activity happens in the given socket the event loop will call the
 * given callback to notify of the activity.
 */
class SocketNotifier {
	private $socket;
	private int $type;
	private $callback;

	public const ACTIVITY_READ  = 1;
	public const ACTIVITY_WRITE = 2;
	public const ACTIVITY_ERROR = 4;

	public function __construct($socket, int $type, callable $callback) {
		$this->socket   = $socket;
		$this->type     = $type;
		$this->callback = $callback;
	}

	/**
	 * Returns the socket resource.
	 */
	public function getSocket() {
		return $this->socket;
	}

	/**
	 * Returns type of the activity.
	 */
	public function getType(): int {
		return $this->type;
	}

	/**
	 * Returns the callback
	 */
	public function getCallback() {
		return $this->callback;
	}

	/**
	 * Calls the callback and passes given @a $type to the callback.
	 */
	public function notify(int $type): void {
		call_user_func($this->callback, $type);
	}
}
