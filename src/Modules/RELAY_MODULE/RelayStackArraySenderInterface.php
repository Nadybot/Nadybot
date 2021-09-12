<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

interface RelayStackArraySenderInterface extends RelayStackMemberInterface {
	/**
	 * Send one or more packets to the transport
	 * @param string[] $data
	 * @return string[]
	 */
	public function send(array $data): array;
}
