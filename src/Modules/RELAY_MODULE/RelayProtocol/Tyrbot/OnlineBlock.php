<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

use Spatie\DataTransferObject\Attributes\CastWith;
use Spatie\DataTransferObject\Casters\ArrayCaster;
use Spatie\DataTransferObject\DataTransferObject;

class OnlineBlock extends DataTransferObject {
	public Source $source;
	/** @var User[] */
	#[CastWith(ArrayCaster::class, itemType: User::class)]
	public array $users;
}
