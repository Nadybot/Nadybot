<?php declare(strict_types=1);

namespace Nadybot\Modules\GSP_MODULE;

class GSPShowStartEvent extends GSPEvent {
	public function __construct(
		public Show $show,
	) {
		$this->type = "gsp(show_start)";
	}
}
