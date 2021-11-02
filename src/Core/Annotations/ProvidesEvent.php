<?php declare(strict_types=1);

namespace Nadybot\Core\Annotations;

use Addendum\Annotation;

class ProvidesEvent extends Annotation {
	public ?string $desc = null;
}
