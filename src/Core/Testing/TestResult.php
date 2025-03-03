<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

/** The 3 possible results of running a single test */
enum TestResult {
	/** Incorporate another result for a new total result */
	public function add(self $otherResult): self {
		if ($otherResult === self::Failure) {
			return $otherResult;
		}
		return $this;
	}

	case Success;
	case Failure;
	case Skipped;
}
