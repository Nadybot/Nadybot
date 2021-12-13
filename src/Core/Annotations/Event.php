<?php declare(strict_types=1);

namespace Nadybot\Core\Annotations;

use Addendum\Annotation;

class Event extends Annotation {
	public string $name;
	public string $description;
	public ?int $defaultStatus = null;
	public ?string $help = null;
}
