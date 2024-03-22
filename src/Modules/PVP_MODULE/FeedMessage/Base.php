<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

class Base {
	public const SITE_UPDATE = 'update_site';
	public const GAS_UPDATE = 'update_gas';
	public const TOWER_ATTACK = 'tower_attack';
	public const TOWER_OUTCOME = 'tower_outcome';

	public function __construct(
		public string $type,
	) {
	}
}
