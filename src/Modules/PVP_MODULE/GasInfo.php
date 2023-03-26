<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Core\{Registry, Util};
use Nadybot\Modules\HELPBOT_MODULE\PlayfieldController;
use Nadybot\Modules\PVP_MODULE\FeedMessage\{SiteUpdate, TowerAttack};

class GasInfo {
	private int $time;

	public function __construct(
		private SiteUpdate $site,
		private ?TowerAttack $lastAttack,
		?int $time=null,
	) {
		$this->time = $time ?? time();
	}

	public function currentGas(): ?Gas {
		if (isset($this->site->gas)) {
			return new Gas($this->site->gas);
		}
		return null;
	}

	public function gasAt(int $time): ?Gas {
		$ownHotEnd = $this->getPenaltyEnd();
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
		$time ??= $this->time;
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

		// Determine the time when the penalty from the attack ended, or null if
		// there is/was no penalty
		$penaltyEnd = $this->getPenaltyEnd();

		if (isset($penaltyEnd) && $this->time > $penaltyEnd) {
			// If there is a penalty in the past, the site goes cold
			// when it goes 75% the next time
			$countTime = $this->time;
		} elseif (isset($penaltyEnd)) {
			// If there is a penalty in the future, the site goes cold
			// when it goes to 75% counting from the end of the penalty
			$countTime = $penaltyEnd;
		} else {
			// If there is no penalty: the site goes cold
			// when it goes 75% the next time
			$countTime = null;
		}
		$goesCold = $this->nextClosing($countTime);
		// If $goesCold is null, then there was no penalty and the site
		// is supposed to be at 75% still

		// If the site went hot before it should, try to determine the total time
		// until it's cold again, by checking how long until it should actually go
		// to 25% and if that's no more than 60s in the future, add it to the 6h
		// of the 25% + 5% phase.
		if ($penaltyEnd === null && $goesCold === null) {
			$next25 = $this->next25();
			if (isset($next25) && ($next25 > $this->time) && ($next25 - $this->time <= 60)) {
				return 3600*6 + $next25;
			}
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
		$penaltyEnd = $this->getPenaltyEnd();
		if (!isset($penaltyEnd)) {
			return false;
		}
		if ($penaltyEnd >= $this->time) {
			return true;
		}
		return $this->regularGas()?->gas  === 75;
	}

	/** Get some debug information about the current gas info. Internal */
	public function dump(): string {
		$niceTime = function (?int $time): string {
			if (!isset($time)) {
				return "-";
			}
			return (new \DateTime('now', new \DateTimeZone("UTC")))->setTimestamp($time)->format("d-M-Y H:i:s").
				" ({$time})";
		};
		$niceOffset = function (?int $time): string {
			if (!isset($time)) {
				return "-";
			}
			return (new \DateTime('now', new \DateTimeZone("UTC")))->setTimestamp($time)->format("H:i:s");
		};
		$blob = "";

		/** @var ?PlayfieldController */
		$pfCtrl = Registry::getInstance(PlayfieldController::class);
		assert(isset($pfCtrl));

		/** @var ?Util */
		$util = Registry::getInstance(Util::class);
		assert(isset($util));
		$pf = $pfCtrl->getPlayfieldById($this->site->playfield_id);
		assert(isset($pf));
		$closingOffset = $this->closingOffset();
		assert(isset($closingOffset));
		$blob .= "<header2>{$pf->short_name} {$this->site->site_id}<end>\n".
			"Time:        " . $niceTime($this->time) . "\n".
			"Planted:     " . $niceTime($this->site->plant_time) . "\n".
			"Timings:     75%: " . $niceOffset($closingOffset) ."\n".
			"             25%: " . $niceOffset($closingOffset + 18*3600) ."\n".
			"              5%: " . $niceOffset($closingOffset + 23*3600) ."\n".
			"Current Gas: " . ($this->currentGas()?->colored() ?? "-") . "\n";
		if (isset($this->lastAttack)) {
			$blob .= "Last attack: " . $niceTime($this->lastAttack->timestamp) . "\n";
			if (isset($this->lastAttack->penalizing_ended)) {
				$blob .= "Penalizing:  " . $niceTime($this->lastAttack->penalizing_ended) . "\n";
			}
		} else {
			$blob .= "Last attack: -\n";
		}
		$blob .= "In penalty:  " . json_encode($this->inPenalty()) . "\n";
		if ($this->site->gas === 75) {
			$blob .=  "Going hot:   " . $niceTime($this->goesHot()) . " - ".
				$util->unixtimeToReadable(($this->goesHot() ?? 0) - $this->time, true) . "\n";
		} elseif (isset($this->site->gas)) {
			$blob .= "Going cold:  " . $niceTime($this->goesCold()) . " - ".
				$util->unixtimeToReadable(($this->goesCold() ?? 0) - $this->time, true) . "\n";
		}
		$blob .= "Penalty end: " . $niceTime($this->getPenaltyEnd()) . "\n";
		return "\n" . trim($blob);
	}

	/** Timestamp after $time at which the site will be cold */
	private function nextClosing(?int $time=null): ?int {
		$closingOffset = $this->closingOffset();
		// Unplanted sites don't have a known offset
		if (!isset($closingOffset)) {
			return null;
		}
		$regGas = $this->regularGas($time);
		// If at the given timestamp, the regular gas will be 75%, then
		// the site will close at $time
		if ($regGas?->gas === 75) {
			return $time;
		}
		// If not, get the next timestamp after $time when it will we
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
		$time ??= $this->time;
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

	private function getPenaltyEnd(): ?int {
		if (!isset($this->site->plant_time) || !isset($this->lastAttack)) {
			return null;
		}
		// Sites go cold again only at their offset and they stay hot for
		// at least 1 hour and at most 2 hours
		$siteOffset = $this->site->plant_time % 3600;
		$attackBase = $this->lastAttack->timestamp - $this->lastAttack->timestamp % 3600;
		$predictedEnd = $attackBase + $siteOffset + 2 * 3600;
		if ($predictedEnd > $this->lastAttack->timestamp + 2 * 3600) {
			$predictedEnd -= 3600;
		}
		// If the attack is already over, we know how long to extend it
		$maxEnd = $this->lastAttack->penalizing_ended ?? $this->time;
		while ($predictedEnd < $maxEnd) {
			$predictedEnd += 3600;
		}
		return $predictedEnd;
	}
}
