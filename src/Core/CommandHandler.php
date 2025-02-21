<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Types\AccessLevel;

class CommandHandler {
	/** @var list<string> */
	public array $files;

	public function __construct(
		public AccessLevel $access_level,
		string ...$fileName
	) {
		$this->files = array_values($fileName);
	}

	public function addFile(string ...$file): self {
		$this->files = array_values(array_merge($this->files, $file));
		return $this;
	}
}
