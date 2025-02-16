<?php declare(strict_types=1);

namespace Nadybot\Modules\GSP_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'gsp(*)')]
abstract class GSPEvent {
	public function __construct(
		public Show $show,
	) {
	}
}
