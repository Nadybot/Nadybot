<?php declare(strict_types=1);

namespace Nadybot\Core\Annotations;

use Addendum\Annotation;

class RequestBody extends Annotation {
	public string $class;
	public string $desc;
	public bool $required;
}
