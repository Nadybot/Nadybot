<?php declare(strict_types=1);

namespace Nadybot\Modules\GSP_MODULE;

class GSPShowEndEvent extends GSPEvent {
	public const EVENT_MASK = "gsp(show_end)";

	public function __construct(
		public Show $show,
	) {
		$this->type = self::EVENT_MASK;
	}
}
