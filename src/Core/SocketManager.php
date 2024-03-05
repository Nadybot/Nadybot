<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;
use Revolt\EventLoop;

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
			$socketNotifier->readHandle = EventLoop::onReadable(
				$socketNotifier->getSocket(),
				function (string $watcherId, mixed $socket) use ($socketNotifier) {
					$socketNotifier->notify(SocketNotifier::ACTIVITY_READ);
				}
			);
		}
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_WRITE) {
			$socketNotifier->writeHandle = EventLoop::onWritable(
				$socketNotifier->getSocket(),
				function (string $watcherId, mixed $socket) use ($socketNotifier) {
					$socketNotifier->notify(SocketNotifier::ACTIVITY_WRITE);
				}
			);
		}
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_ERROR) {
		}
	}

	/** Removes given socket notifier from list of sockets being monitored. */
	public function removeSocketNotifier(SocketNotifier $socketNotifier): void {
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_READ) {
			if (isset($socketNotifier->readHandle)) {
				EventLoop::cancel($socketNotifier->readHandle);
			}
		}
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_WRITE) {
			if (isset($socketNotifier->writeHandle)) {
				EventLoop::cancel($socketNotifier->writeHandle);
			}
		}
		if ($socketNotifier->getType() & SocketNotifier::ACTIVITY_ERROR) {
		}
	}
}
