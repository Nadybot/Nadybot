<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

/** The total results of all tests run */
class TestResults {
	public int $numSuccesses = 0;
	public int $numFailures = 0;
	public int $numSkipped = 0;

	/**
	 * Merge a single test result into ours
	 *
	 * @return $this
	 */
	public function addTest(TestResult $result): self {
		$newNum = match ($result) {
			TestResult::Success => $this->numSuccesses += 1,
			TestResult::Failure => $this->numFailures += 1,
			TestResult::Skipped => $this->numSkipped += 1,
		};
		return $this;
	}

	/**
	 * Merge another given Results into ours
	 *
	 * @return $this
	 */
	public function addResults(self $result): self {
		$this->numSuccesses += $result->numSuccesses;
		$this->numFailures += $result->numFailures;
		$this->numSkipped += $result->numSkipped;
		return $this;
	}
}
