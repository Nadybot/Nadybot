<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\Attributes\Hydrator\StrFormat;
use Nadybot\Core\ExportCharacter;

class ExportNews {
	/**
	 * @param string                    $news        The news text itself
	 * @param ?ExportCharacter          $author      The character who posted the news
	 * @param ?string                   $uuid        The unique identifier of this news entry
	 * @param ?int                      $addedTime   Timestamp of when the news were posted
	 * @param ?bool                     $pinned      Whether the news are sticky, i.e. pinned to be on top
	 * @param ?bool                     $deleted     Whether the news were deleted
	 * @param ?ExportNewsConfirmation[] $confirmedBy
	 *
	 * @psalm-param ?list<ExportNewsConfirmation> $confirmedBy
	 */
	public function __construct(
		public string $news,
		public ?ExportCharacter $author=null,
		#[
			StrFormat('^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$')
		] public ?string $uuid=null,
		public ?int $addedTime=null,
		public ?bool $pinned=null,
		public ?bool $deleted=null,
		#[CastListToType(ExportNewsConfirmation::class)] public ?array $confirmedBy=null,
	) {
	}
}
