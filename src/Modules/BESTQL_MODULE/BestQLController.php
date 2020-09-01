<?php declare(strict_types=1);

namespace Nadybot\Modules\BESTQL_MODULE;

use Nadybot\Core\{
	CommandReply,
	SettingManager,
	Text,
};

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'bestql',
 *		accessLevel = 'all',
 *		description = 'Find breakpoints for bonuses',
 *		help        = 'bestql.txt',
 *		alias       = 'breakpoints'
 *	)
 */
class BestQLController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public SettingManager $settingManager;

	/**
	 * Try to determine the bonus for an interpolated QL
	 *
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

	/**
	 * @HandlesCommand("bestql")
	 * @Matches("/^bestql ([0-9 ]+)$/i")
	 * @Matches("{^bestql ([0-9 ]+) (<a href=(?:&#39;|'|\x22)itemref://\d+/\d+/\d+(?:&#39;|'|\x22)>[^<]+</a>)$}i")
	 */
	public function bestqlCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var array<int,int> */
		$itemSpecs = [];
		$itemToScale = null;
		if (count($args) > 2) {
			$itemPattern = "{<a href=(?:&#39;|'|\")itemref://(\d+)/(\d+)/(\d+)(?:&#39;|'|\")>([^<]+)</a>}";
			preg_match($itemPattern, $args[2], $itemToScale);
		}
		$specPairs = preg_split('/\s+/', $args[1]);

		if (count($specPairs) < 4) {
			$msg = "You have to provide at least 2 bonuses at 2 different QLs.";
			$sendto->reply($msg);
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
				$sendto->reply($msg);
				return;
			}
			$value = (int)round($value);
			if (count($specPairs) % 2) {
				if ($value > $maxAttribute) {
					$msg = "The highest QL is <highlight>".($searchedQL-1)."<end> with a requirement of <highlight>$oldRequirement<end>. QL $searchedQL already requires $value.";
					$sendto->reply($msg);
					return;
				}
				$oldRequirement = $value;
			} elseif ($oldValue !== $value) {
				$msg .= sprintf(
					"<tab>QL %s has stat <highlight>%d<end>.",
					$this->text->alignNumber($searchedQL, 3, "highlight"),
					$value
				);
				if ($itemToScale) {
					$msg .= " " . $this->text->makeItem((int)$itemToScale[1], (int)$itemToScale[2], $searchedQL, $itemToScale[4]);
				}
				$msg .= "\n";
				$numFoundItems++;
				$oldValue = $value;
			}
		}

		$blob = $this->text->makeBlob("breakpoints", $msg, "Calculated breakpoints for your item");
		if (is_string($blob)) {
			$msg = "Found <highlight>$numFoundItems<end> $blob with different stats.";
			$sendto->reply($msg);
			return;
		}
		$pages = [];
		for ($i = 0; $i < count($blob); $i++) {
			$pages[] = "Found <highlight>$numFoundItems<end> ".$blob[$i]." with different stats.";
		}
		$sendto->reply($pages);
	}
}
