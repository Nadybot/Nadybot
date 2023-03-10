<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Handlers;

use Nadybot\Modules\PVP_MODULE\FeedMessage\SiteUpdate;

abstract class Base {
	public function __construct(
		protected string $value
	) {
		$this->validateValue();
	}

	abstract public function matches(SiteUpdate $site): bool;

	abstract protected function validateValue(): void;
}
