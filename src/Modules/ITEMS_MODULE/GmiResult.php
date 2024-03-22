<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class GmiResult {
	/**
	 * @param GmiBuyOrder[]  $buyOrders
	 * @param GmiSellOrder[] $sellOrders
	 */
	public function __construct(
		#[CastListToType(GmiBuyOrder::class)] public array $buyOrders,
		#[CastListToType(GmiSellOrder::class)] public array $sellOrders,
	) {
	}
}
