<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE;

use Nadybot\Core\ExportCharacter;

class ExportCategory {
	/**
	 * @param string           $name           The name of this category
	 * @param ?ExportCharacter $createdBy      The character who created the category
	 * @param ?int             $createdAt      When was the category created?
	 * @param ?bool            $systemEntry    If set, this denounces a system-entry. What that is, is up to  the implementation, but it usually means it's locked, cannot be deleted, etc.
	 * @param ?string          $minRankToRead  The minimum rank required to read comments in this category
	 * @param ?string          $minRankToWrite The minimum rank required to create or delete comments in this category
	 */
	public function __construct(
		public string $name,
		public ?ExportCharacter $createdBy=null,
		public ?int $createdAt=null,
		public ?bool $systemEntry=null,
		public ?string $minRankToRead=null,
		public ?string $minRankToWrite=null,
	) {
	}
}
