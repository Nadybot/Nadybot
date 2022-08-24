<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes\JSON;

class ProxyCapabilities extends ProxyReply {
	public const SEND_ROUND_ROBIN = "round-robin";
	public const SEND_BY_CHARID = "by-charid";
	public const SEND_BY_MSGID = "by-msgid";
	public const SEND_BY_WORKER = "by-worker";
	public const SEND_PROXY_DEFAULT = "proxy-default";

	public const CMD_CAPABILITIES = "capabilities";
	public const CMD_PING = "ping";

	/** Name of the proxy software */
	public ?string $name = "unknown";

	/** Version of the proxy software */
	public ?string $version = "unknown";

	/**
	 * Modes the proxy supports for sending messages
	 *
	 * @var string[]
	 */
	#[JSON\Name("send-modes")]
	public array $send_modes = [];

	/**
	 * Modes the proxy supports for adding buddies
	 *
	 * @var string[]
	 */
	#[JSON\Name("buddy-modes")]
	public array $buddy_modes = [];

	/**
	 * Commands the proxy supports in general
	 *
	 * @var string[]
	 */
	#[JSON\Name("supported-cmds")]
	public array $supported_cmds = [];

	/** Set when the proxy enforces rate-limits */
	#[JSON\Name("rate-limited")]
	public bool $rate_limited = false;

	/** The mode the proxy will use when sending proxy-default */
	#[JSON\Name("default-mode")]
	public ?string $default_mode;

	/** Unix timestamp when the proxy was started */
	#[JSON\Name("started-at")]
	public ?int $started_at;

	/**
	 * Names of the workers
	 *
	 * @var string[]
	 */
	public array $workers = [];

	/**
	 * UIDs of the workers
	 *
	 * @var int[]
	 */
	#[JSON\Ignore]
	public array $worker_uids = [];

	/** Check if the proxy supports a send mode */
	public function supportsSendMode(string $sendMode): bool {
		return in_array($sendMode, $this->send_modes, true);
	}

	/** Check if the proxy supports a buddy mode */
	public function supportsBuddyMode(string $buddyMode): bool {
		return in_array($buddyMode, $this->buddy_modes, true);
	}

	/** Check if the proxy supports mode selectors */
	public function supportsSelectors(): bool {
		return $this->name !== "unknown";
	}

	/** Check if the proxy supports a command */
	public function supportsCommand(string $command): bool {
		return in_array($command, $this->supported_cmds, true);
	}
}
