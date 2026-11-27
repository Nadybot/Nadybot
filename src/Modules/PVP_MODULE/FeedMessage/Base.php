<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use Nadybot\Core\Types\Dimension;

class Base {
	public const string SITE_UPDATE = 'update_site';
	public const string GAS_UPDATE = 'update_gas';
	public const string TOWER_ATTACK = 'tower_attack';
	public const string TOWER_OUTCOME = 'tower_outcome';

	/** The dimension this event is for */
	public int $dimension;

	public function __construct(
		public string $type,
		?int $dimension=null,
	) {
		$this->dimension = $dimension ?? Dimension::RK5->value;
	}
}
