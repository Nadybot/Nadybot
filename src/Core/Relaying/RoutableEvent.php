<?php declare(strict_types=1);

namespace Nadybot\Core\Relaying;

use Nadybot\Core\Event;

class RoutableEvent extends Event {
	public const TYPE_MESSAGE = "routable(message)";
	public const TYPE_USER_STATE = "routable(user_state)";

	protected ?Character $char = null;
	protected array $path = [];
	protected $data = null;

	public function getType(): string {
		return $this->type;
	}

	public function setType(string $type): self {
		$this->type = $type;
		return $this;
	}

	public function setCharacter(Character $char): self {
		$this->char = $char;
		return $this;
	}

	public function getCharacter(): ?Character {
		return $this->char;
	}

	/** @return Source[] */
	public function getPath(): array {
		return $this->path;
	}

	public function prependPath(Source $source): self {
		array_unshift($this->path, $source);
		return $this;
	}

	public function appendPath(Source $source): self {
		array_push($this->path, $source);
		return $this;
	}

	public function getData() {
		return $this->data;
	}

	public function setData($data): self {
		$this->data = $data;
		return $this;
	}
}
