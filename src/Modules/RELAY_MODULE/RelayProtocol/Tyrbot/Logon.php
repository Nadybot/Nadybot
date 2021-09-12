<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

class Logon extends Packet {
	public string $type = "logon";
	public User $user;
	public Source $source;
}
