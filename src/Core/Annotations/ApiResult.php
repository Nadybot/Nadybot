<?php declare(strict_types=1);

namespace Nadybot\Core\Annotations;

use Addendum\Annotation;

class ApiResult extends Annotation {
	public int $code;
	public string $class;
	public string $desc;
}
