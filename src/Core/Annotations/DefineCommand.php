<?php declare(strict_types=1);

namespace Nadybot\Core\Annotations;

use Addendum\Annotation;

class DefineCommand extends Annotation {
	public ?string $command = null;
	public ?string $channels = null;
	public ?string $accessLevel = null;
	public ?string $description = null;
	public ?string $help = null;
	public ?int $defaultStatus = null;
	public ?string $alias = null;
}
