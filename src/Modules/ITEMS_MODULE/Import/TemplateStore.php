<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Import;

use function Safe\unpack;

use Generator;

/**
 * The templates needed to build item rows, stored as packed strings
 *
 * An ItemTemplate object needs about four times the memory of its packed
 * form, so objects are only created when a template is read.
 */
final class TemplateStore {
	/** ql, icon, slot, flags, properties, itemClass as 64 bit integers, then froobFriendly */
	private const FORMAT = 'q6C';
	private const UNPACK = 'qql/qicon/qslot/qflags/qproperties/qitemClass/Cfroob';
	private const HEADER_SIZE = 6 * 8 + 1;
	private const NO_QL = \PHP_INT_MIN;

	/** @var array<int,string> */
	private array $templates = [];

	public function add(int $aoid, ItemTemplate $template): void {
		$this->templates[$aoid] = pack(
			self::FORMAT,
			$template->ql ?? self::NO_QL,
			$template->icon,
			$template->slot,
			$template->flags,
			$template->properties,
			$template->itemClass,
			$template->froobFriendly ? 1 : 0,
		) . $template->name;
	}

	public function get(int $aoid): ?ItemTemplate {
		$data = $this->templates[$aoid] ?? null;
		if ($data === null) {
			return null;
		}

		/** @var array{ql:int,icon:int,slot:int,flags:int,properties:int,itemClass:int,froob:int} */
		$values = unpack(self::UNPACK, $data);
		return new ItemTemplate(
			ql: $values['ql'] === self::NO_QL ? null : $values['ql'],
			name: substr($data, self::HEADER_SIZE),
			icon: $values['icon'],
			slot: $values['slot'],
			flags: $values['flags'],
			properties: $values['properties'],
			itemClass: $values['itemClass'],
			froobFriendly: $values['froob'] === 1,
		);
	}

	/**
	 * Get the AOIDs of all templates, in the order they were added
	 *
	 * @return Generator<int,int>
	 */
	public function aoids(): Generator {
		foreach ($this->templates as $aoid => $_) {
			yield $aoid;
		}
	}
}
