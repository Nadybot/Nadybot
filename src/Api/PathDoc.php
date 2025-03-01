<?php declare(strict_types=1);

namespace Nadybot\Api;

use Nadybot\Core\Attributes\Http;

class PathDoc {
	/** @var list<string> */
	public array $tags = [];

	/** @var list<string> */
	public array $methods = [];

	/** @var array<int,Http\ApiResult> */
	public array $responses = [];
	public ?Http\RequestBody $requestBody = null;

	public function __construct(
		public string $description,
		public string $path,
	) {
	}
}
