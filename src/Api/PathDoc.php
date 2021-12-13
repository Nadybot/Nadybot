<?php declare(strict_types=1);

namespace Nadybot\Api;

use Nadybot\Core\Attributes as NCA;
use ReflectionMethod;

class PathDoc {
	public string $description;
	public string $path;
	public array $tags = [];
	/** @var string[] */
	public array $methods = [];
	/** @var NCA\ApiResult[] */
	public array $responses = [];
	public ?NCA\RequestBody $requestBody = null;
	public ReflectionMethod $phpMethod;
}
