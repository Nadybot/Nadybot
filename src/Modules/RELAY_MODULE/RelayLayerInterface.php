<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

interface RelayLayerInterface extends RelayStackArraySenderInterface {
	/** Receive a packet and process it */
	public function receive(RelayMessage $msg): ?RelayMessage;
}
