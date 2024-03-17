<?php declare(strict_types=1);

namespace Nadybot\Modules\GSP_MODULE;

class GSPShowEndEvent extends GSPEvent {
	public function __construct(
		public Show $show,
	) {
		$this->type = "gsp(show_end)";
	}
}
