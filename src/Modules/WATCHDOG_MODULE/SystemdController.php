<?php declare(strict_types=1);

namespace Nadybot\Modules\WATCHDOG_MODULE;

use Nadybot\Core\Event;
use Nadybot\Core\EventManager;

/**
 * Authors:
 *  - Nadyita (RK5)
 *
 * @Instance
 */

class SystemdController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public EventManager $eventManager;

	public const EINVAL = 22;

	protected bool $enabled = false;
	protected int $watchdogInterval = 0;
	protected int $lastPing = 0;

	/** @Setup */
	public function setup(): void {
		$usec = 0;
		$this->enabled = $this->isSystemdWatchdogEnabled(false, $usec) === 1;
		if ($this->enabled) {
			$this->watchdogInterval = max(1, (int)floor($usec / 1_000_000));
			$this->eventManager->activateIfDeactivated($this, "watchdogPing");
		} else {
			$this->eventManager->deactivateIfActivated($this, "watchdogPing");
		}
	}

	/**
	 * @Event("timer(1sec)")
	 * @Description("Handle SystemD watchdog")
	 * @DefaultStatus("0")
	 */
	public function watchdogPing(Event $event): void {
		if (!$this->enabled || $this->lastPing + $this->watchdogInterval > time()) {
			return;
		}
		$this->notify(false, 'WATCHDOG=1');
		$this->lastPing = time();
	}

	/**
	 * sd_notify PHP implementation
	 *
	 * @link https://www.freedesktop.org/software/systemd/man/sd_notify.html
	 */
	public function notify(bool $unsetEnvironment, string $state): int {
		return $this->notifyWithFDs(0, $unsetEnvironment, $state, []);
	}

	/**
	 * sd_pid_notify_with_fds PHP implementation
	 *
	 * @link https://github.com/systemd/systemd/blob/master/src/libsystemd/sd-daemon/sd-daemon.c
	 */
	public function notifyWithFDs(int $pid, bool $unsetEnvironment, string $state, array $fds): int {
		[$fd, $result] = $this->sdPidNotifyWithFDs($pid, $state, $fds);
		if (isset($fd) && $fd) {
			socket_close($fd);
		}

		if ($unsetEnvironment) {
			putenv('NOTIFY_SOCKET');
		}

		return $result;
	}

	public function sdPidNotifyWithFDs(int $pid, string $state, array $fds): array {
		$state = trim($state);

		if ($state === '') {
			$result = -1 * self::EINVAL;
			return [null, $result];
		}

		$notifySocket = getenv('NOTIFY_SOCKET');
		if ($notifySocket === false) {
			return [null, 0];
		}

		// Must be an abstract socket, or an absolute path
		if (strlen($notifySocket) < 2 || (strpos($notifySocket, '@') !== 0 && strpos($notifySocket, '/') !== 0)) {
			$result = -1 * self::EINVAL;
			return [null, $result];
		}

		$fd = socket_create(AF_UNIX, SOCK_DGRAM, 0);
		if ($fd === false) {
			$result = -1 * socket_last_error();
			return [$fd, $result];
		}

		$messageHeader = [
			'name' => [
				'path' => $notifySocket,
			],
			'iov' => [
				$state . "\n",
			],
			'control' => [],
		];
		if (strpos($messageHeader['name']['path'], '@') === 0) {
			$messageHeader['name'][0] = "\x00";
		}

		$havePID = $pid && getmypid() !== $pid;

		if (count($fds) > 0 || $havePID) {
			if (count($fds)) {
				$messageHeader['control'][] = [
					'level' => SOL_SOCKET,
					'type' => SCM_RIGHTS,
					'data' => $fds
				];
			}

			if ($havePID) {
				$messageHeader['control'][] = [
					'level' => SOL_SOCKET,
					'type' => SCM_CREDENTIALS,
					'data' => [
						'pid' => $pid,
						'uid' => getmyuid(),
						'gid' => getmygid()
					]
				];
			}
		}

		// First try with fake ucred data, as requested
		if (@socket_sendmsg($fd, $messageHeader, MSG_NOSIGNAL) !== false) {
			$result = 1;
			return [$fd, $result];
		}

		// If that failed, try with our own ucred instead
		if ($havePID) {
			$messageHeader['control'] = [];

			if (@socket_sendmsg($fd, $messageHeader, MSG_NOSIGNAL) !== false) {
				return [$fd, 1];
			}
		}

		$result = -1 * socket_last_error($fd);

		return [$fd, $result];
	}

	/**
	 * sd_watchdog_enabled PHP implementation
	 *
	 * @link https://github.com/systemd/systemd/blob/master/src/libsystemd/sd-daemon/sd-daemon.c
	 */
	public function isSystemdWatchdogEnabled(bool $unsetEnvironment, int &$usec): int {
		$result = $this->systemdWatchdogEnabled($usec);
		if ($unsetEnvironment && getenv('WATCHDOG_USEC') !== false) {
			putenv('WATCHDOG_USEC');
		}
		if ($unsetEnvironment && getenv('WATCHDOG_PID') !== false) {
			putenv('WATCHDOG_PID');
		}

		return $result;
	}

	public function systemdWatchdogEnabled(int &$usec): int {
		$watchdogUsec = getenv('WATCHDOG_USEC');
		if ($watchdogUsec === false) {
			return 0;
		}

		if (!filter_var($watchdogUsec, FILTER_VALIDATE_INT)) {
			return -1 * self::EINVAL;
		}
		$watchdogUsec = (int)$watchdogUsec;

		if ($watchdogUsec <= 0) {
			return -1 * self::EINVAL;
		}

		$watchdogPID = getenv('WATCHDOG_PID');
		if ($watchdogPID === false) {
			$usec = $watchdogUsec;
			return 1;
		}
		$pid = (int)$watchdogPID;
		if ($pid < 1) {
			return -1 * self::EINVAL;
		}

		// Is this for us?
		if (getmypid() !== $pid) {
			return 0;
		}

		$usec = $watchdogUsec;
		return 1;
	}
}
