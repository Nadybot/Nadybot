<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use EventSauce\ObjectHydrator\PropertyCasters\CastToType;

class Proxy {
	/**
	 * @param bool   $enabled Whether to enable (true) proxy usage or not
	 * @param string $server  hostname or IP address of the proxy server
	 * @param int    $port    Port of the proxy server
	 */
	public function __construct(
		#[CastToType("bool")]
		public bool $enabled=false,
		public string $server='127.0.0.1',
		#[CastToType("int")]
		public int $port=9993,
	) {
	}
}
