<?php declare(strict_types=1);

namespace Nadybot\Core\SettingHandlers;

use Exception;
use Nadybot\Core\Attributes as NCA;

/** This setting represents a value out of a list of allowed values */
#[NCA\SettingHandler('options')]
class OptionsSettingHandler extends SettingHandler {
	/** {@inheritDoc} */
	public function getDescription(): string {
		$msg = "For this setting you must choose one of the options from the list below.\n\n";
		return $msg;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \Exception if the option is invalid
	 */
	public function save(string $newValue): string {
		$options = explode(';', $this->row->options??'');
		if (isset($this->row->intoptions) && $this->row->intoptions !== '') {
			$intoptions = explode(';', $this->row->intoptions);
			if (in_array($newValue, $intoptions, true)) {
				return $newValue;
			}
			throw new Exception('This is not a correct option for this setting.');
		}
		if (in_array($newValue, $options, true)) {
			return $newValue;
		}
		throw new Exception('This is not a correct option for this setting.');
	}
}
