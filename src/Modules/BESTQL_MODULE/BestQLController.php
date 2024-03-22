<?php declare(strict_types=1);

namespace Nadybot\Modules\BESTQL_MODULE;

use function Safe\preg_split;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	ParamClass\PItem,
	Text,
};

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'bestql',
		accessLevel: 'guest',
		description: 'Find breakpoints for bonuses',
		alias: 'breakpoints'
	)
]
class BestQLController extends ModuleInstance {
	#[NCA\Inject]
	private Text $text;

	/**
	 * Try to determine the bonus for an interpolated QL
	 *
	 * @param array<int,int> $itemSpecs  An associative array [QLX => bonus X, QLY => bonus Y]
	 * @param int            $searchedQL The QL we want to interpolate to
	 *
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
					return $lastSpec[1] + (($itemBonus-$lastSpec[1]) * ($multi *($searchedQL-($lastSpec[0]-1)-1)));
				}
				$lastSpec = [$itemQL, $itemBonus];
			}
		}
		return null;
	}

	/** Find the breakpoints for all possible bonuses of an item */
	#[NCA\HandlesCommand('bestql')]
	public function bestql1Command(
		CmdContext $context,
		int $lowQl,
		int $lowqlBonus,
		int $highQl,
		int $highqlBonus,
		?PItem $pastedItem
	): void {
		$this->bestqlCommand($context, "{$lowQl} {$lowqlBonus} {$highQl} {$highqlBonus}", $pastedItem);
	}

	/** Find the highest usable QL of an item */
	#[NCA\HandlesCommand('bestql')]
	public function bestql2Command(
		CmdContext $context,
		int $lowQl,
		int $lowqlRequirement,
		int $highQl,
		int $highqlRequirement,
		int $attributeValue,
		?PItem $pastedItem
	): void {
		$this->bestqlCommand($context, "{$lowQl} {$lowqlRequirement} {$highQl} {$highqlRequirement} {$attributeValue}", $pastedItem);
	}

	/** General syntax, need at least 4 values for the specs. Paste item for links */
	#[NCA\HandlesCommand('bestql')]
	#[NCA\Help\Epilogue(
		"<header2>Examples:<end>\n\n".
		"Platinum Filigree Ring set with a Perfectly Cut Amber. QL 1 bonus is 6, QL 400 bonus is 23:\n".
		"<tab><highlight><symbol>bestql 1 6 400 23<end>\n\n".
		"<tab>QL <highlight><black>__<end>1<end> has stat <highlight>6<end>.\n".
		"<tab>QL <highlight><black>_<end>13<end> has stat <highlight>7<end>.\n".
		"<tab>QL <highlight><black>_<end>37<end> has stat <highlight>8<end>.\n".
		"<tab>QL <highlight><black>_<end>60<end> has stat <highlight>9<end>.\n".
		"<tab>QL <highlight><black>_<end>84<end> has stat <highlight>10<end>.\n".
		"<tab>QL <highlight>107<end> has stat <highlight>11<end>.\n".
		"<tab>QL <highlight>131<end> has stat <highlight>12<end>.\n".
		"<tab>QL <highlight>154<end> has stat <highlight>13<end>.\n".
		"<tab>QL <highlight>178<end> has stat <highlight>14<end>.\n".
		"<tab>QL <highlight>201<end> has stat <highlight>15<end>.\n".
		"<tab>QL <highlight>224<end> has stat <highlight>16<end>.\n".
		"<tab>QL <highlight>248<end> has stat <highlight>17<end>.\n".
		"<tab>QL <highlight>271<end> has stat <highlight>18<end>.\n".
		"<tab>QL <highlight>295<end> has stat <highlight>19<end>.\n".
		"<tab>QL <highlight>318<end> has stat <highlight>20<end>.\n".
		"<tab>QL <highlight>342<end> has stat <highlight>21<end>.\n".
		"<tab>QL <highlight>366<end> has stat <highlight>22<end>.\n".
		"<tab>QL <highlight>389<end> has stat <highlight>23<end>.\n\n".
		"Carbonum armor. Agility requirement at QL 1 is 8 at QL 200 it's 476. Our agility is 200:\n".
		"<tab><highlight><symbol>bestql 1 8 200 476 200<end>\n\n".
		"<tab>The highest QL is <highlight>82<end> with a requirement of <highlight>198<end>.\n\n".
		"Note: in order to get the best results, it's important to get the correct QLs of an item.\n".
		'The <highlight><symbol>items<end> command should work in most cases, but '.
		"<a href='chatcmd:///start https://aoitems.com/home/'>AOItems</a> might be better.\n"
	)]
	public function bestqlCommand(
		CmdContext $context,
		#[NCA\Regexp('[0-9 ]+')] string $specs,
		?PItem $pastedItem
	): void {
		/** @var array<int,int> */
		$itemSpecs = [];
		$specPairs = preg_split('/\s+/', $specs);

		if (count($specPairs) < 4) {
			$msg = 'You have to provide at least 2 bonuses at 2 different QLs.';
			$context->reply($msg);
			return;
		}

		for ($i = 1; $i < count($specPairs); $i += 2) {
			$itemSpecs[(int)$specPairs[$i-1]] = (int)$specPairs[$i];
		}

		/** @phpstan-var non-empty-array<int,int> $itemSpecs */

		ksort($itemSpecs);

		$msg = "<header2>Breakpoints<end>\n";
		$numFoundItems = 0;
		$oldRequirement = 0;
		$maxAttribute = $specPairs[count($specPairs)-1];
		$oldValue = null;
		for ($searchedQL = (int)min(array_keys($itemSpecs)); $searchedQL <= max(array_keys($itemSpecs)); $searchedQL++) {
			$value = $this->calcStatFromQL($itemSpecs, $searchedQL);
			if ($value === null) {
				$msg = 'I was unable to find any breakpoints for the given stats.';
				$context->reply($msg);
				return;
			}
			$value = (int)round($value);
			if (count($specPairs) % 2) {
				if ($value > $maxAttribute) {
					if ($searchedQL === 1) {
						$msg = 'Your stats are too low to equip any QL of this item.';
					} else {
						$msg = 'The highest QL is <highlight>'.($searchedQL-1)."<end> with a requirement of <highlight>{$oldRequirement}<end>. QL {$searchedQL} already requires {$value}.";
					}
					$context->reply($msg);
					return;
				}
				$oldRequirement = $value;
			} elseif ($oldValue !== $value) {
				$msg .= sprintf(
					'<tab>QL %s has stat <highlight>%d<end>.',
					$this->text->alignNumber($searchedQL, 3, 'highlight'),
					$value
				);
				if ($pastedItem) {
					$msg .= ' ' . $this->text->makeItem($pastedItem->lowID, $pastedItem->highID, $searchedQL, $pastedItem->name);
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

		$blob = $this->text->makeBlob('breakpoints', $msg, 'Calculated breakpoints for your item');
		if (is_string($blob)) {
			$msg = "Found <highlight>{$numFoundItems}<end> {$blob} with different stats.";
			$context->reply($msg);
			return;
		}
		$pages = [];
		for ($i = 0; $i < count($blob); $i++) {
			$pages[] = "Found <highlight>{$numFoundItems}<end> ".$blob[$i].' with different stats.';
		}
		$context->reply($pages);
	}
}
