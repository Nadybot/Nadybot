<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

class TrackerConfig {
	/**
	 * @param TrackerArgument[] $arguments
	 * @param string[]          $events
	 */
	public function __construct(
		public array $arguments=[],
		public array $events=[],
	) {
	}
}
