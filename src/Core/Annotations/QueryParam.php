<?php declare(strict_types=1);

namespace Nadybot\Core\Annotations;

use Addendum\Annotation;

class QueryParam extends Annotation {
	public string $name;
	public bool $required = false;
	public string $in = "query";
	public string $desc = "I was to lame to add a descrpition";
	public string $type = "string";
}
