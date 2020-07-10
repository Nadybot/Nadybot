<?php

namespace Budabot\Core\Annotations;

use Addendum\Annotation;

class DefineCommand extends Annotation {
	public $command;
	public $channels;
	public $accessLevel;
	public $description;
	public $help;
	public $defaultStatus;
	public $alias;
}
