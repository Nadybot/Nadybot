<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

class Logoff extends Packet {
	public string $type = "logoff";
	public User $user;
	public Source $source;
}
