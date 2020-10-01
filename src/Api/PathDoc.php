<?php declare(strict_types=1);

namespace Nadybot\Api;

use Addendum\ReflectionAnnotatedMethod;
use Nadybot\Core\Annotations\ApiResult;

class PathDoc {
	public string $description;
	public string $path;
	/** @var string[] */
	public array $methods = [];
	/** @var ApiResult[] */
	public array $responses = [];
	public ReflectionAnnotatedMethod $phpMethod;
}
