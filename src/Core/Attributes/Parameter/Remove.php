<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Attribute;

/** This parameter must be a valid "remove" argument like rem, del, delete, remove, etc. */
#[Attribute(Attribute::TARGET_PARAMETER)]
class Remove extends Str {
	public function __construct() {
		parent::__construct('rem', 'remove', 'delete', 'erase', 'del', 'rm', 'off');
	}
}
