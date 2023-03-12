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
		$goesCold = $this->nextClosing($ownHotEnd);
		while (isset($goesCold) && $goesCold < time() - 10) {
			$goesCold += 3600;
		}
		return $goesCold;
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

	/** Check if this site is only hot because of an attack */
	public function inPenalty(): bool {
		if (!isset($this->site->gas) || $this->site->gas === 75) {
			return false;
		}
		$ownHotEnd = $this->getOwnHotEnd();
		if (!isset($ownHotEnd)) {
			return false;
		}
		if ($ownHotEnd >= time()) {
			return true;
		}
		return $this->regularGas()?->gas  === 75;
	}

	/** Timestamp after $time at which the site will be cold */
	private function nextClosing(?int $time=null): ?int {
		$closingOffset = $this->closingOffset();
		if (!isset($closingOffset)) {
			return null;
		}
		$regGas = $this->regularGas($time);
		if ($regGas?->gas === 75) {
			return $time;
		}
		return $this->nextCycle($closingOffset, $time);
	}

	private function next25(?int $time=null): ?int {
		$closingOffset = $this->closingOffset();
		if (!isset($closingOffset)) {
			return null;
		}
		$regGas = $this->regularGas($time);
		if ($regGas?->gas === 25) {
			return $time;
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
		// Sites go cold again only at their offset and they stay hot for
		// at least 1 hour and at most 2 hours
		$siteOffset = $this->site->plant_time % 3600;
		$attackBase = $this->lastAttack - $this->lastAttack % 3600;
		$predictedEnd = $attackBase + $siteOffset + 2 * 3600;
		if ($predictedEnd > $this->lastAttack + 2 * 3600) {
			$predictedEnd -= 3600;
		}
		return $predictedEnd;
	}
}
