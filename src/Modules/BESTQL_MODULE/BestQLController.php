<?php declare(strict_types=1);

namespace Nadybot\Modules\BESTQL_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	CmdContext,
	Instance,
	SettingManager,
	Text,
};
use Nadybot\Core\ParamClass\PItem;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "bestql",
		accessLevel: "all",
		description: "Find breakpoints for bonuses",
		help: "bestql.txt",
		alias: "breakpoints"
	)
]
class BestQLController extends Instance {

		#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public SettingManager $settingManager;

	/**
	 * Try to determine the bonus for an interpolated QL
	 * @param array<int,int> $itemSpecs An associative array [QLX => bonus X, QLY => bonus Y]
	 * @param int $searchedQL The QL we want to interpolate to
	 * @return float|null The interpolated bonus at the given QL or null if out of range
	 */
	public function calcStatFromQL(array $itemSpecs, int $searchedQL): ?float {
		$lastSpec = null;
		foreach ($itemSpecs as $itemQL => $itemBonus) {
			if ($lastSpec === null) {
				$lastSpec = [$itemQL, $itemBonus];
			} else {
				if ($lastSpec[0] <= $searchedQL && $itemQL >= $searchedQL) {
					$multi = (1 / ($itemQL - $lastSpec[0]));
					return $lastSpec[1] + ( ($itemBonus-$lastSpec[1]) * ($multi *($searchedQL-($lastSpec[0]-1)-1)));
				} else {
					$lastSpec = [$itemQL, $itemBonus];
				}
			}
		}
		return null;
	}

	#[NCA\HandlesCommand("bestql")]
	public function bestqlCommand(CmdContext $context, #[NCA\Regexp("[0-9 ]+")] string $specs, ?PItem $item): void {
		/** @var array<int,int> */
		$itemSpecs = [];
		$specPairs = preg_split('/\s+/', $specs);

		if (count($specPairs) < 4) {
			$msg = "You have to provide at least 2 bonuses at 2 different QLs.";
			$context->reply($msg);
			return;
		}

		for ($i = 1; $i < count($specPairs); $i += 2) {
			$itemSpecs[(int)$specPairs[$i-1]] = (int)$specPairs[$i];
		}

		ksort($itemSpecs);

		$msg = "<header2>Breakpoints<end>\n";
		$numFoundItems = 0;
		$oldRequirement = 0;
		$maxAttribute = $specPairs[count($specPairs)-1];
		$oldValue = null;
		for ($searchedQL = (int)min(array_keys($itemSpecs)); $searchedQL <= max(array_keys($itemSpecs)); $searchedQL++) {
			$value = $this->calcStatFromQL($itemSpecs, $searchedQL);
			if ($value === null) {
				$msg = "I was unable to find any breakpoints for the given stats.";
				$context->reply($msg);
				return;
			}
			$value = (int)round($value);
			if (count($specPairs) % 2) {
				if ($value > $maxAttribute) {
					if ($searchedQL === 1) {
						$msg = "Your stats are too low to equip any QL of this item.";
					} else {
						$msg = "The highest QL is <highlight>".($searchedQL-1)."<end> with a requirement of <highlight>$oldRequirement<end>. QL $searchedQL already requires $value.";
					}
					$context->reply($msg);
					return;
				}
				$oldRequirement = $value;
			} elseif ($oldValue !== $value) {
				$msg .= sprintf(
					"<tab>QL %s has stat <highlight>%d<end>.",
					$this->text->alignNumber($searchedQL, 3, "highlight"),
					$value
				);
				if ($item) {
					$msg .= " " . $this->text->makeItem($item->lowID, $item->highID, $searchedQL, $item->name);
				}
				$msg .= "\n";
				$numFoundItems++;
				$oldValue = $value;
			}
		}
		if (count($specPairs) % 2) {
			$maxQL = max(array_keys($itemSpecs));
			$msg = "The highest QL is <highlight>{$maxQL}<end> with a requirement of <highlight>{$itemSpecs[$maxQL]}<end>.";
			$context->reply($msg);
			return;
		}

		$blob = $this->text->makeBlob("breakpoints", $msg, "Calculated breakpoints for your item");
		if (is_string($blob)) {
			$msg = "Found <highlight>$numFoundItems<end> $blob with different stats.";
			$context->reply($msg);
			return;
		}
		$pages = [];
		for ($i = 0; $i < count($blob); $i++) {
			$pages[] = "Found <highlight>$numFoundItems<end> ".$blob[$i]." with different stats.";
		}
		$context->reply($pages);
	}
}
