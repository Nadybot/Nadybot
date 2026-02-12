<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE;

class Reputation {
	/**
	 * Represents the reputation of a character
	 *
	 * @param int           $total    The total reputation of the character
	 * @param list<Comment> $comments The comments about the character
	 */
	public function __construct(
		public int $total=0,
		public array $comments=[],
	) {
	}
}
