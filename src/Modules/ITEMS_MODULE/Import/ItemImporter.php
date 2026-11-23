<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Import;

use function Safe\preg_match;

use Closure;
use JsonException;
use Nadybot\Core\Types\{ItemProperty, ItemStat, Skill};
use Nadybot\Modules\ITEMS_MODULE\{AODBEntry, AodbType, ItemBuff, ItemGroup, ItemGroupName, ItemType};
use Nadybot\Modules\ITEMS_MODULE\Exceptions\ItemImportException;
use Nadybot\Modules\SKILLS_MODULE\WeaponAttribute;

/**
 * Converts an item data export into the rows of the item tables
 *
 * The document is read element by element, so only the data needed to
 * build the item rows at the end is kept in memory. Buffs, types and
 * weapon data are passed on to the sink right away.
 */
final class ItemImporter {
	/** Templates matching this are not turned into items */
	public const IMPORT_FILTER = '/^.+ Implant.+(Shiny|Bright|Faded)|^(Blister Pack|Pill with)|_\d{3}$/';

	public const MIN_QL = 1;
	public const MAX_QL = 500;

	private ItemImportResult $result;

	/** @var array<int,ItemTemplate> */
	private array $templates = [];

	/** @var array<int,true> */
	private array $filtered = [];

	/** @var list<array{?string,list<array{int,int,int,int}>}> */
	private array $families = [];

	/** @param Closure(list<string>):string $commonName Calculates the name of a family from the names of its templates */
	public function __construct(
		private ItemRowSink $sink,
		private Closure $commonName,
	) {
	}

	/**
	 * Import the export, given as a sequence of uncompressed chunks
	 *
	 * @param iterable<string> $chunks    The JSON document
	 * @param array<int,true>  $notInGame AOIDs of templates that are not in the game
	 *
	 * @throws ItemImportException if the document cannot be used
	 */
	public function import(iterable $chunks, array $notInGame): ItemImportResult {
		$this->result = new ItemImportResult();
		$this->templates = [];
		$this->filtered = [];
		$this->families = [];
		$splitter = new JsonElementSplitter();
		$seen = [];
		try {
			foreach ($chunks as $chunk) {
				$this->result->bytes += strlen($chunk);
				foreach ($splitter->feed($chunk) as [$section, $key, $raw]) {
					$seen[$section] = true;
					if ($section === 'version') {
						$this->handleVersion($raw);
					} elseif ($section === 'families') {
						$this->handleFamily($raw);
					} elseif ($section === 'templates') {
						$this->handleTemplate($key, $raw);
					}
				}
			}
			$splitter->finish();
		} catch (JsonException $e) {
			throw new ItemImportException('Invalid JSON: ' . $e->getMessage(), 0, $e);
		}
		if ($this->result->version === '') {
			throw new ItemImportException('The export has no version');
		}
		if (!isset($seen['templates'])) {
			throw new ItemImportException('The export has no templates');
		}
		$this->createItems($notInGame);
		$this->sink->flush();
		if ($this->result->items() === 0) {
			throw new ItemImportException('The export does not contain any items');
		}
		$this->templates = [];
		$this->filtered = [];
		$this->families = [];
		return $this->result;
	}

	private function handleVersion(string $raw): void {
		$version = json_decode($raw, false, 2, \JSON_THROW_ON_ERROR);
		if (is_int($version)) {
			$version = (string)$version;
		}
		if (!is_string($version) || $version === '') {
			throw new ItemImportException('The version of the export is invalid');
		}
		$this->result->version = $version;
	}

	private function handleFamily(string $raw): void {
		$this->result->families++;
		$family = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
		if (!is_array($family) || !isset($family['items']) || !is_array($family['items'])) {
			$this->result->skip('family without items');
			return;
		}
		$name = $family['name'] ?? null;
		if (!is_string($name) || $name === '') {
			$name = null;
		}
		$items = [];
		foreach ($family['items'] as $item) {
			$parsed = $this->parseItem($item);
			if (is_string($parsed)) {
				$this->result->skip($parsed);
				continue;
			}
			$items []= $parsed;
		}
		if (count($items)) {
			$this->families []= [$name, $items];
		}
	}

	/** @return array{int,int,int,int}|string The item as [lowid, lowql, highid, highql] or the reason why it is invalid */
	private function parseItem(mixed $item): array|string {
		if (!is_array($item) || !array_is_list($item) || count($item) < 1 || count($item) > 2) {
			return 'item without 1 or 2 steps';
		}
		$steps = [];
		foreach ($item as $step) {
			if (!is_array($step) || count($step) !== 2) {
				return 'item with an invalid step';
			}
			$aoid = $step[0] ?? null;
			$ql = $step[1] ?? null;
			if (!is_int($aoid) || !is_int($ql)) {
				return 'item with an invalid step';
			}
			if ($ql < self::MIN_QL || $ql > self::MAX_QL) {
				return 'item with a QL out of range';
			}
			$steps []= [$aoid, $ql];
		}
		$low = $steps[0];
		$high = $steps[1] ?? $steps[0];
		if ($low[1] > $high[1]) {
			return 'item with low QL above high QL';
		}
		return [$low[0], $low[1], $high[0], $high[1]];
	}

	private function handleTemplate(?string $key, string $raw): void {
		$this->result->templates++;
		if ($key === null || !ctype_digit($key)) {
			$this->result->skip('template with an invalid AOID');
			return;
		}
		$aoid = (int)$key;
		$template = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
		$name = is_array($template) ? ($template['name'] ?? null) : null;
		if (!is_array($template) || !is_string($name)) {
			$this->result->skip('template without name');
			return;
		}
		$stats = $template['stats'] ?? [];
		if (!is_array($stats)) {
			$stats = [];
		}
		$this->addBuffs($aoid, $template['buffs'] ?? []);
		$this->addTypes($aoid, $template['types'] ?? []);
		$this->addWeapon($aoid, $stats);

		if (preg_match(self::IMPORT_FILTER, $name)) {
			$this->filtered[$aoid] = true;
			$this->result->filteredTemplates++;
			return;
		}
		$ql = $template['ql'] ?? null;
		$this->templates[$aoid] = new ItemTemplate(
			ql: is_int($ql) ? $ql : null,
			name: $name,
			icon: $this->intStat($stats, ItemStat::Icon->value),
			slot: $this->intStat($stats, ItemStat::Slot->value),
			flags: $this->intStat($stats, ItemStat::Flags->value),
			properties: $this->intStat($stats, ItemStat::Can->value),
			itemClass: $this->intStat($stats, ItemStat::ItemClass->value),
			froobFriendly: ($template['froob_friendly'] ?? true) !== false,
		);
	}

	private function addBuffs(int $aoid, mixed $buffs): void {
		if (!is_array($buffs)) {
			return;
		}
		foreach ($buffs as $attribute => $amount) {
			if (is_array($amount)) {
				$amount = $amount[0] ?? null;
			}
			if (!is_int($attribute) || !is_int($amount)) {
				$this->result->skip('invalid buff');
				continue;
			}
			$this->sink->add(ItemBuff::class, [
				'item_id' => $aoid,
				'attribute_id' => $attribute,
				'amount' => $amount,
			]);
			$this->result->buffs++;
		}
	}

	private function addTypes(int $aoid, mixed $types): void {
		if (!is_array($types)) {
			return;
		}
		foreach ($types as $type) {
			if (!is_string($type) || $type === '') {
				$this->result->skip('invalid type');
				continue;
			}
			$this->sink->add(ItemType::class, [
				'item_id' => $aoid,
				'item_type' => $type,
			]);
			$this->result->types++;
		}
	}

	/** @param array<mixed> $stats */
	private function addWeapon(int $aoid, array $stats): void {
		$attack = $stats[ItemStat::AttackDelay->value] ?? null;
		$recharge = $stats[ItemStat::RechargeDelay->value] ?? null;
		if (!is_int($attack) || !is_int($recharge)) {
			return;
		}
		$can = $this->intStat($stats, ItemStat::Can->value);
		$this->sink->add(WeaponAttribute::class, [
			'id' => $aoid,
			'attack_time' => $attack,
			'recharge_time' => $recharge,
			'full_auto' => $this->optStat($stats, ItemStat::FullAutoRecharge->value),
			'burst' => $this->optStat($stats, ItemStat::BurstRecharge->value),
			'fling_shot' => ItemProperty::FLING_SHOT->in($can) ? 1 : 0,
			'fast_attack' => ItemProperty::FAST_ATTACK->in($can) ? 1 : 0,
			'aimed_shot' => ItemProperty::AIMED_SHOT->in($can) ? 1 : 0,
			'brawl' => ItemProperty::BRAWL->in($can) ? 1 : 0,
			'sneak_attack' => ItemProperty::SNEAK_ATTACK->in($can) ? 1 : 0,
			'multi_m' => $this->optStat($stats, Skill::MultiMelee->value),
			'multi_r' => $this->optStat($stats, Skill::MultiRanged->value),
		]);
		$this->result->weapons++;
	}

	/** @param array<mixed> $notInGame */
	private function createItems(array $notInGame): void {
		$referenced = [];
		foreach ($this->families as [, $items]) {
			foreach ($items as [$lowId, , $highId]) {
				$referenced[$lowId] = true;
				$referenced[$highId] = true;
			}
		}

		$groupId = 0;
		$groupRowId = 0;
		foreach ($this->families as [$name, $items]) {
			$members = [];
			$names = [];
			foreach ($items as [$lowId, $lowQL, $highId, $highQL]) {
				$low = $this->templates[$lowId] ?? null;
				$high = $this->templates[$highId] ?? null;
				if (!isset($low, $high)) {
					$this->result->skip(
						(isset($this->filtered[$lowId]) || isset($this->filtered[$highId]))
							? 'item with a filtered template'
							: 'item with an unknown template'
					);
					continue;
				}
				$this->addItem($lowId, $lowQL, $low, $highId, $highQL, $high, $notInGame);
				$this->result->familyItems++;
				$members[$lowId] = true;
				$members[$highId] = true;
				$names[$low->name] = true;
				$names[$high->name] = true;
			}
			if (!count($members)) {
				continue;
			}
			$groupId++;
			foreach (array_keys($members) as $aoid) {
				$this->sink->add(ItemGroup::class, [
					'id' => ++$groupRowId,
					'group_id' => $groupId,
					'item_id' => $aoid,
				]);
			}
			$name ??= ($this->commonName)(array_keys($names));
			if ($name !== '') {
				$this->sink->add(ItemGroupName::class, [
					'group_id' => $groupId,
					'name' => $name,
				]);
			}
		}
		$this->result->groups = $groupId;

		foreach ($this->templates as $aoid => $template) {
			if (isset($referenced[$aoid])) {
				continue;
			}
			if ($template->ql === null) {
				$this->result->skip('template without QL');
				continue;
			}
			$this->addItem($aoid, $template->ql, $template, $aoid, $template->ql, $template, $notInGame);
			$this->result->directItems++;
		}
	}

	/** @param array<mixed> $notInGame */
	private function addItem(
		int $lowId,
		int $lowQL,
		ItemTemplate $low,
		int $highId,
		int $highQL,
		ItemTemplate $high,
		array $notInGame,
	): void {
		$inGame = !isset($notInGame[$lowId]) && !isset($notInGame[$highId]);
		if (!$inGame) {
			$this->result->notInGame++;
		}
		$this->sink->add(AODBEntry::class, [
			'lowid' => $lowId,
			'highid' => $highId,
			'lowql' => $lowQL,
			'highql' => $highQL,
			'name' => $low->name,
			'icon' => $low->icon,
			'froob_friendly' => ($low->froobFriendly && $high->froobFriendly) ? 1 : 0,
			'slot' => $low->slot,
			'flags' => $low->flags,
			'in_game' => $inGame ? 1 : 0,
			'type' => AodbType::fromTemplate($low->itemClass, $low->name)->value,
			'properties' => $low->properties,
		]);
	}

	/** @param array<mixed> $stats */
	private function intStat(array $stats, int $stat): int {
		$value = $stats[$stat] ?? 0;
		return is_int($value) ? $value : 0;
	}

	/** @param array<mixed> $stats */
	private function optStat(array $stats, int $stat): ?int {
		$value = $stats[$stat] ?? null;
		return is_int($value) ? $value : null;
	}
}
