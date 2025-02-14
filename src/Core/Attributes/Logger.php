<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** Inject an instance of the bot's LoggingInterface implementation */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Logger {
	public function __construct(public ?string $tag=null) {
	}
}
