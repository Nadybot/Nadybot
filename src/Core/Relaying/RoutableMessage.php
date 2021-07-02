<?php declare(strict_types=1);

namespace Nadybot\Core\Relaying;

class RoutableMessage extends RoutableEvent {
	public function __construct(string $message) {
		$this->setType(self::TYPE_MESSAGE);
		$this->setData($message);
	}

	public function getData(): string {
		return (string)parent::getData();
	}
}
