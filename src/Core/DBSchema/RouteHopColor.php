<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBRow};

class RouteHopColor extends DBRow {
	/**
	 * @param string  $hop        The hop mask (discord, *, aopriv, ...)
	 * @param ?string $where      The channel for which to apply these colors or null for all
	 * @param ?string $via        Only apply this color if the event was routed via this hop
	 * @param ?string $tag_color  The 6 hex digits of the tag color, like FFFFFF
	 * @param ?string $text_color The 6 hex digits of the text color, like FFFFFF
	 * @param ?int    $id         Internal primary key
	 */
	public function __construct(
		public string $hop,
		public ?string $where=null,
		public ?string $via=null,
		public ?string $tag_color=null,
		public ?string $text_color=null,
		#[NCA\JSON\Ignore] #[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}
}
