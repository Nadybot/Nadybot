<?php declare(strict_types=1);

namespace Nadybot\Core;

/**
 * @Instance
 */
class SocketManager {
	/** @var SocketNotifier[] */
	private array $socketNotifiers = [];
	private array $monitoredSocketsByType = [
		SocketNotifier::ACTIVITY_READ  => [],
		SocketNotifier::ACTIVITY_WRITE => [],
		SocketNotifier::ACTIVITY_ERROR => [],
	];

	public function checkMonitoredSockets(): bool {
		$read   = array_filter($this->monitoredSocketsByType[SocketNotifier::ACTIVITY_READ] ?? [], "is_resource");
		$write  = array_filter($this->monitoredSocketsByType[SocketNotifier::ACTIVITY_WRITE] ?? [], "is_resource");
		$except = array_filter($this->monitoredSocketsByType[SocketNotifier::ACTIVITY_ERROR] ?? [], "is_resource");
		if (empty($read) && empty($write) && empty($except)) {
			return false;
		}
		if (stream_select($read, $write, $except, 0) === 0) {
			return false;
		}
		foreach ($this->socketNotifiers as $notifier) {
			$socket = $notifier->getSocket();
			$type = $notifier->getType();

			if (in_array($socket, $read) && $type & SocketNotifier::ACTIVITY_READ) {
				$notifier->notify(SocketNotifier::ACTIVITY_READ);
			}
			if (isset($write) && in_array($socket, $write) && $type & SocketNotifier::ACTIVITY_WRITE) {
				$notifier->notify(SocketNotifier::ACTIVITY_WRITE);
			}
			if (isset($except) && in_array($socket, $except) && $type & SocketNotifier::ACTIVITY_ERROR) {
				$notifier->notify(SocketNotifier::ACTIVITY_ERROR);
			}
		}
		return true;
	}

	/**
	 * Adds given socket notifier to list of sockets which are
	 * monitored for activity.
	 */
	public function addSocketNotifier(SocketNotifier $socketNotifier): void {
		$this->socketNotifiers []= $socketNotifier;

		// add the socket to each activity category for faster access in the event loop
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_READ) {
			$this->monitoredSocketsByType[SocketNotifier::ACTIVITY_READ][] = $socketNotifier->getSocket();
		}
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_WRITE) {
			$this->monitoredSocketsByType[SocketNotifier::ACTIVITY_WRITE][] = $socketNotifier->getSocket();
		}
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_ERROR) {
			$this->monitoredSocketsByType[SocketNotifier::ACTIVITY_ERROR][] = $socketNotifier->getSocket();
		}
	}

	/**
	 * Removes given socket notifier from list of sockets being monitored.
	 */
	public function removeSocketNotifier(SocketNotifier $socketNotifier): void {
		$this->removeOne($this->socketNotifiers, $socketNotifier);

		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_READ) {
			$this->removeOne($this->monitoredSocketsByType[SocketNotifier::ACTIVITY_READ], $socketNotifier->getSocket());
		}
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_WRITE) {
			$this->removeOne($this->monitoredSocketsByType[SocketNotifier::ACTIVITY_WRITE], $socketNotifier->getSocket());
		}
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_ERROR) {
			$this->removeOne($this->monitoredSocketsByType[SocketNotifier::ACTIVITY_ERROR], $socketNotifier->getSocket());
		}
	}

	private function removeOne(array &$array, $value): void {
		$key = array_search($value, $array, true);
		if ($key !== false) {
			unset($array[$key]);
		}
	}
}
