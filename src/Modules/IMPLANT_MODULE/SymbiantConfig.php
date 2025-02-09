<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Types\ImplantSlot;

class SymbiantConfig {
	/** @var list<Symbiant> */
	public array $eye = [];

	/** @var list<Symbiant> */
	public array $head = [];

	/** @var list<Symbiant> */
	public array $ear = [];

	/** @var list<Symbiant> */
	public array $rarm = [];

	/** @var list<Symbiant> */
	public array $chest = [];

	/** @var list<Symbiant> */
	public array $larm = [];

	/** @var list<Symbiant> */
	public array $rwrist = [];

	/** @var list<Symbiant> */
	public array $waist = [];

	/** @var list<Symbiant> */
	public array $lwrist = [];

	/** @var list<Symbiant> */
	public array $rhand = [];

	/** @var list<Symbiant> */
	public array $legs = [];

	/** @var list<Symbiant> */
	public array $lhand = [];

	/** @var list<Symbiant> */
	public array $feet = [];

	public function set(Symbiant $symbiant): void {
		match ($symbiant->slot) {
			ImplantSlot::Eye => $this->eye []= $symbiant,
			ImplantSlot::Head => $this->head []= $symbiant,
			ImplantSlot::Ear => $this->ear []= $symbiant,
			ImplantSlot::RightArm => $this->rarm []= $symbiant,
			ImplantSlot::Chest => $this->chest []= $symbiant,
			ImplantSlot::LeftArm => $this->larm []= $symbiant,
			ImplantSlot::RightWrist => $this->rwrist []= $symbiant,
			ImplantSlot::Waist => $this->waist []= $symbiant,
			ImplantSlot::LeftWrist => $this->lwrist []= $symbiant,
			ImplantSlot::RightHand => $this->rhand []= $symbiant,
			ImplantSlot::Leg => $this->legs []= $symbiant,
			ImplantSlot::LeftHand => $this->lhand []= $symbiant,
			ImplantSlot::Feet => $this->feet []= $symbiant,
		};
	}

	/** @return list<Symbiant> */
	public function get(ImplantSlot $slot): array {
		return match ($slot) {
			ImplantSlot::Eye => $this->eye,
			ImplantSlot::Head => $this->head,
			ImplantSlot::Ear => $this->ear,
			ImplantSlot::RightArm => $this->rarm,
			ImplantSlot::Chest => $this->chest,
			ImplantSlot::LeftArm => $this->larm,
			ImplantSlot::RightWrist => $this->rwrist,
			ImplantSlot::Waist => $this->waist,
			ImplantSlot::LeftWrist => $this->lwrist,
			ImplantSlot::RightHand => $this->rhand,
			ImplantSlot::Leg => $this->legs,
			ImplantSlot::LeftHand => $this->lhand,
			ImplantSlot::Feet => $this->feet,
		};
	}
}
