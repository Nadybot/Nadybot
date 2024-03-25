<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class Comment extends DBRow {
	/** Unix timestamp when the comment was created */
	public int $created_at;

	/**
	 * @param string $character  About whom the comment is
	 * @param string $created_by Who created the comment?
	 * @param string $category   Category of the comment
	 * @param ?int   $created_at Unix timestamp when the comment was created
	 * @param string $comment    The actual comment
	 * @param ?int   $id         The internal id of the comment
	 */
	public function __construct(
		public string $character,
		public string $created_by,
		public string $category,
		?int $created_at=null,
		public string $comment='',
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
		$this->created_at = $created_at ?? time();
	}
}
