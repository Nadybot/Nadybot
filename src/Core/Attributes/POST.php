<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This endpoint listens for POST requests */
#[Attribute(Attribute::TARGET_METHOD)]
class POST extends VERB {
}
