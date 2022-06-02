<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\Loop;
use Nadybot\Core\Attributes as NCA;

#[NCA\Instance]
class SocketManager {
	public function checkMonitoredSockets(): bool {
		return false;
	}

	/**
	 * Adds given socket notifier to list of sockets which are
	 * monitored for activity.
	 */
	public function addSocketNotifier(SocketNotifier $socketNotifier): void {
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_READ) {
			$socketNotifier->readHandle = Loop::onReadable(
				$socketNotifier->getSocket(),
				function (string $watcherId, mixed $socket, mixed $data) use ($socketNotifier) {
					$socketNotifier->notify(SocketNotifier::ACTIVITY_READ);
				}
			);
		}
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_WRITE) {
			$socketNotifier->writeHandle = Loop::onWritable(
				$socketNotifier->getSocket(),
				function (string $watcherId, mixed $socket, mixed $data) use ($socketNotifier) {
					$socketNotifier->notify(SocketNotifier::ACTIVITY_WRITE);
				}
			);
		}
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_ERROR) {
		}
	}

	/**
	 * Removes given socket notifier from list of sockets being monitored.
	 */
	public function removeSocketNotifier(SocketNotifier $socketNotifier): void {
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_READ) {
			if (isset($socketNotifier->readHandle)) {
				Loop::cancel($socketNotifier->readHandle);
			}
		}
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_WRITE) {
			if (isset($socketNotifier->writeHandle)) {
				Loop::cancel($socketNotifier->writeHandle);
			}
		}
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_ERROR) {
		}
	}
}
