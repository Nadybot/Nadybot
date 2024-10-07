<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\Attributes\Exporter\StrFormat;
use Nadybot\Core\ExportCharacter;

class ExportOrgNote {
	/**
	 * @param string           $text         The text of the note
	 * @param ?ExportCharacter $author       The character who posted the note
	 * @param ?int             $creationTime Timestamp of when the note was created
	 * @param ?string          $uuid         The unique identifier for this org note
	 */
	public function __construct(
		public string $text,
		public ?ExportCharacter $author=null,
		public ?int $creationTime=null,
		#[
			StrFormat('^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$')
		] public ?string $uuid=null,
	) {
	}
}
