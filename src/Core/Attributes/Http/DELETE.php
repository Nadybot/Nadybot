<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Http;

use Attribute;

/** This endpoint listens for DELETE requests */
#[Attribute(Attribute::TARGET_METHOD)]
class DELETE extends VERB {
}
