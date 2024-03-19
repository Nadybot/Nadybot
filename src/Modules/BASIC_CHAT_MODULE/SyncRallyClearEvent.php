<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\SyncEvent;

class SyncRallyClearEvent extends SyncEvent {
	public const EVENT_MASK = "sync(rally-clear)";

	/** @param string $owner Character who cleared the rally */
	public function __construct(
		public string $owner,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		$this->type = self::EVENT_MASK;
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}
}
