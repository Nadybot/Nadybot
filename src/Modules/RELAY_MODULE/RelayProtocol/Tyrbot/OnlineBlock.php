<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

use Spatie\DataTransferObject\DataTransferObject;

class OnlineBlock extends DataTransferObject {
	public Source $source;
	/** @var \Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot\User[] */
	public array $users;
}
