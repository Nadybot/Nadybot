<?php declare(strict_types=1);

namespace Nadybot\Core;

/** This is a parsed doc block comment */
class Comment {
	/**
	 * @param string      $headline    The headline (first line) of the doc block
	 * @param null|string $description The rest of the doc block, or `null` if only
	 *                                 the headline
	 */
	public function __construct(
		public readonly string $headline,
		public readonly ?string $description=null,
	) {
	}
}
