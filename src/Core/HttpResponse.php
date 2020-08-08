<?php declare(strict_types=1);

namespace Nadybot\Core;

class HttpResponse {
	/** @var array<string,string> $headers */
	public array $headers = [];
	public ?string $body = null;
	public ?string $error = null;
}
