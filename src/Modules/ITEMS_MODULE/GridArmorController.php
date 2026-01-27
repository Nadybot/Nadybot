<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\Types\{
	AccessLevel,
	EnumBitfield,
	ItemFlag,
	ItemProperty,
	WearSlot
};
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	Collection,
	DB,
	ModuleInstance,
	QueryBuilder,
	Text,
	Types\CommandReply,
};
use ValueError;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'gridarmor',
		alias: 'ga',
		accessLevel: AccessLevel::Guest,
		description: 'Find items that can be worn with GridArmor',
	),
	NCA\DefineCommand(
		command: 'gridarmorfroob',
		alias: 'gaf',
		accessLevel: AccessLevel::Guest,
		description: 'Find froob-friendly items that can be worn with GridArmor',
	),
]
class GridArmorController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private WhatBuffsController $whatBuffsController;

	/** Show a list of slots where you can wear armor */
	#[NCA\HandlesCommand('gridarmor')]
	#[NCA\HandlesCommand('gridarmorfroob')]
	public function gridarmorCommand(CmdContext $context): void {
		$command = explode(' ', $context->message)[0];
		$froobFriendly = strtolower($command) === 'gridarmorfroob';
		$this->showSlotChoice($context, $froobFriendly);
	}

	/** Show the player a list of slots and how many items they can wear in that slot */
	public function showSlotChoice(CommandReply $sendto, bool $froobFriendly): void {
		$command = 'gridarmor' . ($froobFriendly ? 'froob' : '');
		$blob = "<header2>What is Social Armor?<end>\n".
			"<tab>* Grid Armor Mk I-IV\n".
			"<tab>* Hellfyre Magma Suit\n".
			"<tab>* Charred Abaddon Chassis\n".
			"<tab>* All Battle Suits\n\n".
			"<header2>Choose a slot<end>\n";

		$query = $this->db->table(AODBEntry::getTable())
			->select('slot')
			->where('in_game', true)
			->where('type', 'Armor')
			->whereRaw('properties & ' . ItemProperty::CAN_BE_WORN_WITH_SOCIAL_ARMOR->value . ' != 0');
		$query = $this->filterNonsense($query);
		if ($froobFriendly) {
			$query->where('froob_friendly', '=', true);
		}

		/**
		 * @var Collection<int,int>
		 */
		$itemSlots = $query->pluckInts('slot');

		$counts = [];
		foreach ($itemSlots as $slot) {
			$slotBitfield = new EnumBitfield(WearSlot::class);
			$slotBitfield->setInt($slot);
			foreach (WearSlot::cases() as $wearSlot) {
				if ($slotBitfield->has($wearSlot)) {
					if (!isset($counts[$wearSlot->name])) {
						$counts[$wearSlot->name] = 1;
					} else {
						$counts[$wearSlot->name]++;
					}
				}
			}
		}
		foreach (WearSlot::cases() as $slot) {
			if ($slot === WearSlot::Back) {
				// GridArmor is on the back, no point even listing it…
				continue;
			}
			$count = $counts[$slot->name] ?? 0;
			$blob .= '<tab>' . Text::makeChatcmd($slot->longName(), "/tell <myname> {$command} {$slot->longName()}") . " ({$count})\n";
		}
		$blob .= "\nItem Extraction Info provided by AOIA+";
		if ($froobFriendly) {
			$msg = Text::makeBlob('Items wearable for froobs with Social Armor - Choose Slot', $blob);
		} else {
			$msg = Text::makeBlob('Items wearable with Social Armor - Choose Slot', $blob);
		}
		$sendto->reply($msg);
	}

	/** Show a list of items that you can wear with GA in a given slot */
	#[NCA\HandlesCommand('gridarmor')]
	#[NCA\HandlesCommand('gridarmorfroob')]
	public function gridarmor2Command(CmdContext $context, string $slot): void {
		$command = explode(' ', $context->message)[0];
		$froobFriendly = strtolower($command) === 'gridarmorfroob';
		$this->showSlotArmor($context, $slot, $froobFriendly);
	}

	public function showSlotArmor(CmdContext $context, string $slot, bool $froobFriendly): void {
		try {
			$slotBits = WearSlot::fromName($slot);
		} catch (ValueError) {
			$context->reply("Unknown slot <highlight>'{$slot}'<end>");
			return;
		}

		$names = [];
		foreach (WearSlot::cases() as $wearSlot) {
			if ($slotBits->has($wearSlot)) {
				$names []= $wearSlot->longName();
			}
		}
		$slotNames = implode('/', $names);

		$query = $this->db->table(AODBEntry::getTable())
			->where('type', 'Armor')
			->where('in_game', true)
			->whereRaw('properties & ' . ItemProperty::CAN_BE_WORN_WITH_SOCIAL_ARMOR->value . ' != 0')
			->whereRaw('slot & ' . $slotBits->toInt() . ' != 0')
			->orderBy('name');
		$query = $this->filterNonsense($query);
		if ($froobFriendly) {
			$query->where('froob_friendly', '=', true);
		}

		/**
		 * @var Collection<int,AODBEntry>
		 */
		$items = $query->asObj(AODBEntry::class);

		$count = $items->count();

		$title = "Items wearable with Social Armor in {$slotNames} ({$count})";
		if ($froobFriendly) {
			$title = "Items wearable for froobs with Social Armor in {$slotNames} ({$count})";
		}
		$blob = "<header2>{$title}<end>\n";
		foreach ($items as $item) {
			$blob .= "\t" . $this->formatItem($item, $slotBits)."\n";
		}
		$blob .= "\nItem Extraction Info provided by AOIA+";
		$msg = Text::makeBlob($title, $blob);
		$context->reply($msg);
	}

	/**
	 * Format a given item, adding prefix and flags to show
	 *
	 * @param AODBEntry              $item  The item to render
	 * @param EnumBitfield<WearSlot> $slots The slots beinmg shown
	 *
	 * @return string The line listing the item
	 */
	public function formatItem(AODBEntry $item, EnumBitfield $slots): string {
		$wbc = $this->whatBuffsController;
		$showUniques = $wbc->whatbuffsShowUnique;
		$showNodrops = $wbc->whatbuffsShowNodrop;
		$blob = $this->getSlotPrefix($item, $slots);
		$blob .= $item->getLink();
		if ($item->flags->has(ItemFlag::UNIQUE) && $showUniques) {
			$blob .= $showUniques === 1 ? ' U' : ' Unique';
		}
		if ($item->flags->has(ItemFlag::NO_DROP) && $showNodrops) {
			$blob .= $showNodrops === 1 ? ' ND' : ' Nodrop';
		}
		return $blob;
	}

	private function filterNonsense(QueryBuilder $query): QueryBuilder {
		return $query->whereNot('name', 'LIKE', 'Lootgiver%')
			->whereNot('name', 'LIKE', 'Test Test%')
			->whereNot('name', 'LIKE', 'Leet_Pet_NCU_Ring%');
	}

	/**
	 * Show the optional slot prefix, if item is onlt for left/right
	 *
	 * @param AODBEntry              $item  The item to render
	 * @param EnumBitfield<WearSlot> $slots The slots to show
	 */
	private function getSlotPrefix(AODBEntry $item, EnumBitfield $slots): string {
		if (!($item->slot instanceof EnumBitfield)) {
			return '';
		}
		$wbc = $this->whatBuffsController;
		$markSetting = $wbc->whatbuffsDisplay;
		$result = '';
		if ($slots->hasAll(WearSlot::LeftArm, WearSlot::RightArm)) {
			if ($item->slot->hasAll(WearSlot::LeftArm, WearSlot::RightArm)) {
			} elseif ($item->slot->has(WearSlot::LeftArm)) {
				$result = 'L-Arm ';
			} elseif ($item->slot->has(WearSlot::RightArm)) {
				$result = 'R-Arm ';
			}
		}
		if ($slots->hasAll(WearSlot::LeftWrist, WearSlot::RightWrist)) {
			if ($item->slot->hasAll(WearSlot::LeftWrist, WearSlot::RightWrist)) {
			} elseif ($item->slot->has(WearSlot::LeftWrist)) {
				$result = 'L-Wrist ';
			} elseif ($item->slot->has(WearSlot::RightWrist)) {
				$result = 'R-Wrist ';
			}
		}
		if ($slots->hasAll(WearSlot::LeftFinger, WearSlot::RightFinger)) {
			if ($item->slot->hasAll(WearSlot::LeftFinger, WearSlot::RightFinger)) {
			} elseif ($item->slot->has(WearSlot::LeftFinger)) {
				$result = 'L-Finger ';
			} elseif ($item->slot->has(WearSlot::RightFinger)) {
				$result = 'R-Finger ';
			}
		}
		if ($slots->hasAll(WearSlot::LeftShoulder, WearSlot::RightShoulder)) {
			if ($item->slot->hasAll(WearSlot::LeftShoulder, WearSlot::RightShoulder)) {
			} elseif ($item->slot->has(WearSlot::LeftShoulder)) {
				$result = 'L-Shoulder ';
			} elseif ($item->slot->has(WearSlot::RightShoulder)) {
				$result = 'R-Shoulder ';
			}
		}
		if ($markSetting === 0) {
			return '';
		}
		if ($markSetting === 1 && strlen($result) > 1) {
			return substr($result, 0, 1) . ' ';
		}
		return $result;
	}
}
