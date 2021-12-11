<?php declare(strict_types=1);

namespace Nadybot\Core;

class SyncEvent extends Event {
	public string $sourceBot;
	public int $sourceDimension;
	public bool $forceSync = false;

	final public function __construct() {
	}

	public static function fromSyncEvent(SyncEvent $event): self {
		$obj = new static();
		foreach ($event as $key => $value) {
			$obj->{$key} = $value;
		}
		return $obj;
	}

	public function isLocal(): bool {
		if (!isset($this->sourceBot) || !isset($this->sourceDimension)) {
			return true;
		}
		/** @var Nadybot */
		$chatBot = Registry::getInstance("chatBot");
		$myName = $chatBot->char->name;
		$myDim = (int)$chatBot->vars['dimension'];
		return $this->sourceBot === $myName
			&& $this->sourceDimension === $myDim;
	}
}
