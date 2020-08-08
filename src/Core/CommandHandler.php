<?php declare(strict_types=1);

namespace Nadybot\Core;

class CommandHandler {
	public string $file;
	public string $admin;

	public function __construct(string $fileName, string $accessLevel) {
		$this->file = $fileName;
		$this->admin = $accessLevel;
	}
}
