<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\DB\Table(name: 'route_hop_format')]
class RouteHopFormat extends DBTable {
	#[NCA\JSON\Ignore] #[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param string         $hop    The hop mask (discord, *, aopriv, ...)
	 * @param ?string        $where  The channel for which to apply these, or null for all
	 * @param ?string        $via    Only apply these settings if the event was routed via this hop
	 * @param bool           $render Whether to render this tag or not
	 * @param string         $format The format what the text of the tag should look like
	 * @param ?UuidInterface $id     Internal primary key
	 */
	public function __construct(
		public string $hop,
		public ?string $where=null,
		public ?string $via=null,
		public bool $render=true,
		public string $format='%s',
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}
}
