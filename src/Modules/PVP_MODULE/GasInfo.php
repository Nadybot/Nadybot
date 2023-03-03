<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Modules\PVP_MODULE\FeedMessage\{SiteUpdate};

class GasInfo {
	public function __construct(
		private SiteUpdate $site,
		private ?int $lastAttack,
	) {
	}

	public function currentGas(): ?Gas {
		if (isset($this->site->gas)) {
			return new Gas($this->site->gas);
		}
		return null;
	}

	public function gasAt(int $time): ?Gas {
		$ownHotEnd = $this->getOwnHotEnd();
		if ($ownHotEnd > $time) {
			return $this->currentGas();
		}
		return $this->regularGas($time);
	}

	/** Get the gas the site would have at $time if no attacks were made */
	public function regularGas(?int $time=null): ?Gas {
		$timing = $this->gasTiming();
		if (!isset($timing)) {
			return null;
		}
		$time ??= time();
		$offset = $time % 86400;
		$bestGas = null;
		$latestGas = null;
		foreach ($timing as $ts => $gas) {
			if (!isset($latestGas) || $latestGas < $ts) {
				$latestGas = $ts;
			}
			if ($ts > $offset) {
				continue;
			}
			if (!isset($bestGas) || $bestGas < $ts) {
				$bestGas = $ts;
			}
		}
		$bestGas ??= $latestGas;
		assert(isset($timing[$bestGas]));
		return new Gas($timing[$bestGas]);
	}

	/**
	 * Get the timestamp when a currently hot site goes cold
	 *
	 * @return ?int null if the site is currently cold, otherwise a timestamp
	 */
	public function goesCold(): ?int {
		// If the site is unplanted, or currently cold, it's cold by definition
		if (!isset($this->site->gas) || $this->site->gas === 75) {
			return null;
		}
		$ownHotEnd = $this->getOwnHotEnd();
		return $this->nextClosing($ownHotEnd);
	}

	/**
	 * Get the timestamp when a currently cold site goes hot
	 *
	 * @return ?int null if the site is currently hot, otherwise a timestamp
	 */
	public function goesHot(): ?int {
		// If the site is unplanted, or currently cold, it's cold by definition
		if (!isset($this->site->gas) || $this->site->gas !== 75) {
			return null;
		}
		return $this->next25();
	}

	private function nextClosing(?int $time=null): ?int {
		$closingOffset = $this->closingOffset();
		if (!isset($closingOffset)) {
			return null;
		}
		return $this->nextCycle($closingOffset, $time);
	}

	private function next25(?int $time=null): ?int {
		$closingOffset = $this->closingOffset();
		if (!isset($closingOffset)) {
			return null;
		}
		$closingOffset += 18 * 3600;
		return $this->nextCycle($closingOffset % 86400, $time);
	}

	private function nextCycle(int $offset, ?int $time=null): int {
		$time ??= time();
		$nextCycle = $time - ($time % 86400) + $offset;
		while ($time > $nextCycle) {
			$nextCycle += 86400;
		}
		return $nextCycle;
	}

	/** Get the offset for when the site goes to 75% */
	private function closingOffset(): ?int {
		if (!isset($this->site->plant_time)) {
			return null;
		}
		$plantOffset = $this->site->plant_time % 86400;
		if ($this->site->timing === $this->site::TIMING_EU) {
			return (20 * 3600 + $plantOffset % 3600) % 86400;
		} elseif ($this->site->timing === $this->site::TIMING_US) {
			return (4 * 3600 + $plantOffset % 3600) % 86400;
		}
		return $plantOffset;
	}

	/**
	 * Get an array timestamp offset => gas
	 *
	 * @return ?array<int,int>
	 * @psalm-return ?non-empty-array<int,int>
	 */
	private function gasTiming(): ?array {
		$timeClosing = $this->closingOffset();
		if (!isset($timeClosing)) {
			return null;
		}
		$time25 = ($timeClosing + 18 * 3600) % 86400;
		$time5 = ($timeClosing + 23 * 3600) % 86400;
		return [$timeClosing => 75, $time25 => 25, $time5 => 5];
	}

	private function getOwnHotEnd(): ?int {
		if (!isset($this->site->plant_time) || !isset($this->lastAttack)) {
			return null;
		}
		$siteOffset = $this->site->plant_time % 3600;
		return $this->lastAttack + 3600 + $siteOffset;
	}
}
