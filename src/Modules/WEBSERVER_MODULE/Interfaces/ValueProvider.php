<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Interfaces;

interface ValueProvider {
	/** @return array<string,string> */
	public function getTags(): array;

	public function getValue(): int|float|string;
}
