<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Remove extends Str {
	public function __construct() {
		parent::__construct('rem', 'remove', 'delete', 'erase', 'del', 'rm', 'off');
	}
}
