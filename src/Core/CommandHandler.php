<?php declare(strict_types=1);

namespace Nadybot\Core;

class CommandHandler {
	/** @var string[] */
	public array $files;

	public string $access_level;

	public function __construct(string $admin, string ...$fileName) {
		$this->access_level = $admin;
		$this->files = $fileName;
	}

	public function addFile(string ...$file): self {
		$this->files = array_merge($this->files, $file);
		return $this;
	}
}
