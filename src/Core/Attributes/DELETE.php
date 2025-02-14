<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This endpoint listens for DELETE requests */
#[Attribute(Attribute::TARGET_METHOD)]
class DELETE extends VERB {
}
