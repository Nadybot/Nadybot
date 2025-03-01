<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This function should be called for setup */
#[Attribute(Attribute::TARGET_METHOD)]
class Setup extends Event {
	public function __construct() {
		parent::__construct(mask: 'setup');
	}
}
