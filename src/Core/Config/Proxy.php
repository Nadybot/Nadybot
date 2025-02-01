<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use EventSauce\ObjectHydrator\PropertyCasters\CastToType;
use Nadybot\Core\Attributes\ConvertToBool;
use Nadybot\Core\Attributes\Exporter\{Filter, Max, Min};

/** Proxy settings (obsolete) */
class Proxy {
	/**
	 * @param bool   $enabled Whether to enable (true) proxy usage or not
	 * @param string $server  hostname or IP address of the proxy server
	 * @param int    $port    Port of the proxy server
	 */
	public function __construct(
		#[ConvertToBool] public bool $enabled=false,
		#[
			Filter(filter: \FILTER_VALIDATE_IP, options: \FILTER_FLAG_IPV4, type: 'IP address')
		] public string $server='127.0.0.1',
		#[CastToType('int'), Min(1), Max(65_535)] public int $port=9_993,
	) {
	}
}
