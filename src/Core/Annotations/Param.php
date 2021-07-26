<?php declare(strict_types=1);

namespace Nadybot\Core\Annotations;

use Addendum\Annotation;

class Param extends Annotation {
	public string $name;
	public string $type;
	public string $description="";
	public bool $required;
}
