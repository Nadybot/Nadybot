<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[NCA\DB\Table(name: '<table:comments>')]
class Comment extends DBTable {
	/** Unix timestamp when the comment was created */
	public int $created_at;

	/** The internal id of the comment */
	#[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param string         $character  About whom the comment is
	 * @param string         $created_by Who created the comment?
	 * @param string         $category   Category of the comment
	 * @param ?int           $created_at Unix timestamp when the comment was created
	 * @param string         $comment    The actual comment
	 * @param ?UuidInterface $id         The internal id of the comment
	 */
	public function __construct(
		public string $character,
		public string $created_by,
		public string $category,
		?int $created_at=null,
		public string $comment='',
		?UuidInterface $id=null,
	) {
		$this->created_at = $created_at ?? time();
		$dt = null;
		if (isset($created_at) && !isset($id)) {
			$dt = (new DateTimeImmutable())->setTimestamp($created_at);
		}
		$this->id = $id ?? Uuid::uuid7($dt);
	}
}
