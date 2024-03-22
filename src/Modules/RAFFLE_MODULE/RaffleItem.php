<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

use function Safe\preg_match_all;
use Nadybot\Core\{Registry, Safe};
use Nadybot\Modules\ITEMS_MODULE\ItemsController;

use Nadybot\Modules\SPIRITS_MODULE\SpiritsController;

class RaffleItem {
	public int $amount = 1;
	public string $item;

	public function fromString(string $text): void {
		if (count($matches = Safe::pregMatch("/^(?<count>\d+)x?\s*[^\/\d]/", $text))) {
			$this->amount = (int)$matches['count'];
			$text = Safe::pregReplace("/^(\d+)x?\s*/", '', $text);
		}

		/** @var string */
		$text = Safe::pregReplace("/['\"](itemref:\/\/\d+\/\d+\/\d+)['\"]/", '$1', $text);
		$this->item = $text;
	}

	public function toString(): string {
		$item = $this->item;
		if (preg_match_all("/itemref:\/\/(\d+)\/(\d+)\/(\d+)/", $item, $matches) > 0 && is_array($matches)) {
			for ($i = 0; $i < count($matches[0]); $i++) {
				$ql = null;
				if ($matches[1][$i] !== $matches[2][$i]) {
					$ql = $matches[3][$i];
				} else {
					/** @var ItemsController */
					$items = Registry::getInstance(ItemsController::class);

					/** @var SpiritsController */
					$spirits = Registry::getInstance(SpiritsController::class);

					$hasItemGroup = $items->hasItemGroup((int)$matches[1][$i]);
					if ($hasItemGroup) {
						$ql = $matches[3][$i];
					} elseif (str_contains($this->item, 'Unit Aban')
						|| str_contains($this->item, 'Unit Beta')
						|| str_contains($this->item, 'Unit Alpha')
					) {
						$ql = $matches[3][$i];
					} elseif (str_contains($this->item, 'Spirit')) {
						$isSpirit = $spirits->isSpirit((int)$matches[1][$i]);
						if ($isSpirit) {
							$ql = $matches[3][$i];
						}
					}
				}
				if (isset($ql)) {
					/** @var string */
					$item = Safe::pregReplace('/(<a [^>]*?'.preg_quote($matches[0][$i], '/')."\E.*?>)/", "QL{$ql} $1", $item);
				}
			}
		}
		if ($this->amount <= 1) {
			return $item;
		}
		return $this->amount . 'x ' . $item;
	}
}
