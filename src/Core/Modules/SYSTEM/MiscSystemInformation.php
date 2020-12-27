<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Nadybot\Core\ProxyCapabilities;

class MiscSystemInformation {
	/** Is the bot using a chat proxy for mass messages or more than 1000 friends */
	public bool $using_chat_proxy;

	/** If the proxy is used, this describes in detail what the proxy supports */
	public ProxyCapabilities $proxy_capabilities;

	/** Number of seconds since the bot was started */
	public int $uptime;
}
