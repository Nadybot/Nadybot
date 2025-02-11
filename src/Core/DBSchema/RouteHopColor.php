<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Types\HopColorType;
use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\DB\Table(name: 'route_hop_color')]
/** Which colors to use for displaying a hop of a routed message */
class RouteHopColor extends DBTable {
	#[NCA\JSON\Ignore] #[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param string         $hop        The hop mask (discord, *, aopriv, ...)
	 * @param ?string        $where      The channel for which to apply these colors or null for all
	 * @param ?string        $via        Only apply this color if the event was routed via this hop
	 * @param ?string        $tag_color  The 6 hex digits of the tag color, like FFFFFF
	 * @param ?string        $text_color The 6 hex digits of the text color, like FFFFFF
	 * @param ?UuidInterface $id         Internal primary key
	 */
	public function __construct(
		public string $hop,
		public ?string $where=null,
		public ?string $via=null,
		public ?string $tag_color=null,
		public ?string $text_color=null,
		?UuidInterface $id=null,
	) {
		$this->id = $id  ?? Uuid::uuid7();
	}

	public function getColor(HopColorType $type): ?string {
		return match ($type) {
			HopColorType::TagColor => $this->tag_color,
			HopColorType::TextColor => $this->text_color,
		};
	}

	public function setColor(HopColorType $type, ?string $color): ?string {
		return match ($type) {
			HopColorType::TagColor => $this->tag_color = $color,
			HopColorType::TextColor => $this->text_color = $color,
		};
	}
}
