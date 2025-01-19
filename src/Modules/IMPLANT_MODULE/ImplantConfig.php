<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Types\ImplantSlot;

class ImplantConfig {
	/** psalm-param int<1,300> $ql */
	public function __construct(
		public int $ql=300,
		public ?SlotConfig $head=null,
		public ?SlotConfig $eye=null,
		public ?SlotConfig $ear=null,
		public ?SlotConfig $rarm=null,
		public ?SlotConfig $chest=null,
		public ?SlotConfig $larm=null,
		public ?SlotConfig $rwrist=null,
		public ?SlotConfig $waist=null,
		public ?SlotConfig $lwrist=null,
		public ?SlotConfig $rhand=null,
		public ?SlotConfig $legs=null,
		public ?SlotConfig $lhand=null,
		public ?SlotConfig $feet=null,
	) {
	}

	public function getSlot(ImplantSlot $slot): ?SlotConfig {
		return match ($slot) {
			ImplantSlot::Chest => $this->chest,
			ImplantSlot::Ear => $this->ear,
			ImplantSlot::Eye => $this->eye,
			ImplantSlot::Feet => $this->feet,
			ImplantSlot::Head => $this->head,
			ImplantSlot::LeftArm => $this->larm,
			ImplantSlot::LeftHand => $this->lhand,
			ImplantSlot::LeftWrist => $this->lwrist,
			ImplantSlot::Leg => $this->legs,
			ImplantSlot::RightArm => $this->rarm,
			ImplantSlot::RightHand => $this->rhand,
			ImplantSlot::RightWrist => $this->rwrist,
			ImplantSlot::Waist => $this->waist,
		};
	}

	public function setSlot(ImplantSlot $slot, ?SlotConfig $config): void {
		match ($slot) {
			ImplantSlot::Chest => $this->chest = $config,
			ImplantSlot::Ear => $this->ear = $config,
			ImplantSlot::Eye => $this->eye = $config,
			ImplantSlot::Feet => $this->feet = $config,
			ImplantSlot::Head => $this->head = $config,
			ImplantSlot::LeftArm => $this->larm = $config,
			ImplantSlot::LeftHand => $this->lhand = $config,
			ImplantSlot::LeftWrist => $this->lwrist = $config,
			ImplantSlot::Leg => $this->legs = $config,
			ImplantSlot::RightArm => $this->rarm = $config,
			ImplantSlot::RightHand => $this->rhand = $config,
			ImplantSlot::RightWrist => $this->rwrist = $config,
			ImplantSlot::Waist => $this->waist = $config,
		};
	}

	public function setSlotIfUnset(ImplantSlot $slot, SlotConfig $config): SlotConfig {
		return match ($slot) {
			ImplantSlot::Chest => $this->chest ??= $config,
			ImplantSlot::Ear => $this->ear ??= $config,
			ImplantSlot::Eye => $this->eye ??= $config,
			ImplantSlot::Feet => $this->feet ??= $config,
			ImplantSlot::Head => $this->head ??= $config,
			ImplantSlot::LeftArm => $this->larm ??= $config,
			ImplantSlot::LeftHand => $this->lhand ??= $config,
			ImplantSlot::LeftWrist => $this->lwrist ??= $config,
			ImplantSlot::Leg => $this->legs ??= $config,
			ImplantSlot::RightArm => $this->rarm ??= $config,
			ImplantSlot::RightHand => $this->rhand ??= $config,
			ImplantSlot::RightWrist => $this->rwrist ??= $config,
			ImplantSlot::Waist => $this->waist ??= $config,
		};
	}
}
