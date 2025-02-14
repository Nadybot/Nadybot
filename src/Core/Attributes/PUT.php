<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This endpoint listens for PUT requests */
#[Attribute(Attribute::TARGET_METHOD)]
class PUT extends VERB {
}
