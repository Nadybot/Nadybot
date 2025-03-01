<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This is a news tile handler that provides the tile $name */
#[Attribute(Attribute::TARGET_METHOD)]
class NewsTile {
	/**
	 * Provides a news tile handler
	 *
	 * @param string $name        Name of the news tile
	 * @param string $description Description of the news tile
	 * @param string $example     Example output of the news tile
	 */
	public function __construct(
		public string $name,
		public string $description,
		public string $example
	) {
	}
}
