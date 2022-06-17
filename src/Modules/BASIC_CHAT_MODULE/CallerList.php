<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

class CallerList {
	/** Name of this list of callers, e.g. "RI1", "east", or empty string if default */
	public string $name;

	/** Name of the character who created this list */
	public string $creator;

	/**
	 * List of the characters who are callers
	 *
	 * @var Caller[]
	 */
	public array $callers = [];

	/** @return string[] */
	public function getNames(): array {
		return array_column($this->callers, "name");
	}

	/** Check if $name is in this caller list */
	public function isInList(string $name): bool {
		return in_array($name, $this->getNames());
	}

	/** Get the amount of callers on this list */
	public function count(): int {
		return count($this->callers);
	}

	/**
	 * Remove all callers added by $search
	 *
	 * @param string $search       Either the full name or a partial one
	 * @param bool   $partialMatch Do a partial match on $search
	 * @param bool   $invert       if true, remove those NOT matching
	 *
	 * @return Caller[] The removed callers
	 */
	public function removeCallersAddedBy(string $search, bool $partialMatch, bool $invert): array {
		if (!$partialMatch) {
			$search = ucfirst(strtolower($search));
		}
		$removed = [];
		$this->callers = array_values(
			array_filter(
				$this->callers,
				function (Caller $caller) use ($search, &$removed, $partialMatch, $invert): bool {
					$remove = false;
					if (!$partialMatch) {
						$remove = $search === $caller->addedBy;
					} elseif (strncasecmp($caller->addedBy, $search, strlen($search)) === 0) {
						$remove = true;
					}
					$remove = $invert ? !$remove : $remove;
					if (!$remove) {
						return true;
					}
					$removed []= $caller;
					return false;
				}
			)
		);
		return $removed;
	}
}
