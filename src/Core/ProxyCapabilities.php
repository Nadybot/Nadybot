<?php declare(strict_types=1);

namespace Nadybot\Core;

class ProxyCapabilities {
	public const SEND_ROUND_ROBIN = "round-robin";
	public const SEND_BY_CHARID = "by-charid";
	public const SEND_BY_MSGID = "by-msgid";
	public const SEND_BY_WORKER = "by-worker";
	public const SEND_PROXY_DEFAULT = "proxy-default";

	/** Name of the proxy software */
	public ?string $name = "unknown";

	/** Version of the proxy software */
	public ?string $version = "unknown";

	/**
	 * Modes the proxy support for sending messages
	 * @json:name=send-modes
	 * @var string[]
	 */
	public array $send_modes = [];

	/**
	 * The mode the proxy will use when sending proxy-default
	 * @json:name=default-mode
	 */
	public ?string $default_mode;

	/**
	 * Unix timestamp when the proxy was started
	 * @json:name=started-at
	 */
	public ?int $started_at;

	/**
	 * Names of the workers
	 * @var string[]
	 */
	public array $workers = [];

	/** Check if the proxy supports a send mode */
	public function supportsSendMode(string $sendMode): bool {
		return in_array($sendMode, $this->send_modes, true);
	}

	/** Check if the proxy supports mode selectors */
	public function supportsSelectors(): bool {
		return $this->name !== "unknown";
	}
}
