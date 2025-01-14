<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

/** A public channel */
class ChannelInfo {
	public const ORG = 3;
	public const READ_ONLY = 10;
	public const NEWS = 12;
	public const OOC = 135;

	/**
	 * @param string $name  The name of the public channel
	 * @param int    $id    The ID the game uses for this channel
	 * @param int    $class The class of the channel (OOC, towers, etc.)
	 */
	public function __construct(
		public string $name,
		public int $id,
		public int $class,
	) {
	}
}
