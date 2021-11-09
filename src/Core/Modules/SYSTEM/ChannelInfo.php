<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

class ChannelInfo {
	public const ORG = 3;
	public const READ_ONLY = 10;
	public const NEWS = 12;
	public const OOC = 135;

	/** The name of the public channel */
	public string $name;

	/** The ID the game uses for this channel */
	public int $id;

	/** The class of the channel (OOC, towers, etc.) */
	public int $class;
}
