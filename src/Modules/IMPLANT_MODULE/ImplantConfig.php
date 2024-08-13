<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

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
}
