<?php declare(strict_types=1);

namespace Nadybot\Core\SettingHandlers;

use function Safe\array_flip;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	DBSchema\Setting,
	Text,
	Types\AccessLevel,
	Types\SettingMode
};

/**
 * This is the abstract base class for all setting handlers.
 * It provides everything needed to validate saved values,
 * display current value(s), display possible values, etc.
 */
abstract class SettingHandler {
	#[NCA\Inject]
	private AccessManager $accessManager;

	/** Construct a new handler out of a given database row */
	public function __construct(
		protected Setting $row
	) {
	}

	/** Check if this setting can be changed by the user */
	public function isEditable(): bool {
		return $this->row->mode === SettingMode::Edit;
	}

	/** Can the user of the given command context see the clear text value of this setting? */
	public function canViewValue(CmdContext $context): bool {
		if ($this->row->confidential !== true) {
			return true;
		}
		if (!$context->isDM()) {
			return false;
		}
		$alToChange = $this->row->access_level ?? AccessLevel::Superadmin;
		return $this->accessManager->checkAccess($context->char->name, $alToChange);
	}

	/** Get the low level data setting object */
	public function getData(): Setting {
		return $this->row;
	}

	/** Get a link to change this setting's value */
	public function getModifyLink(): string {
		return Text::makeChatcmd('modify', '/tell <myname> settings change ' . $this->row->name);
	}

	/** Get a displayable representation of the setting */
	public function displayValue(string $sender): string {
		if (!isset($this->row->intoptions) || $this->row->intoptions === '') {
			return '<highlight>' . htmlspecialchars($this->row->value??'<empty>') . '<end>';
		}
		$options = explode(';', $this->row->options ?? '');
		$intoptions = explode(';', $this->row->intoptions);
		$intoptions2 = array_flip($intoptions);
		if (!isset($this->row->value)) {
			return '<highlight>&lt;empty&gt;<end>';
		}
		$key = $intoptions2[$this->row->value];
		return '<highlight>' . ($options[$key] ?? '&lt;empty&gt;') . '<end>';
	}

	/** Get all options for this setting or null if no options are available */
	public function getOptions(): ?string {
		if (!strlen($this->row->options??'')) {
			return null;
		}
		$options = explode(';', $this->row->options??'');
		if (strlen($this->row->intoptions??'')) {
			$intoptions = explode(';', $this->row->intoptions??'');
			$optionsMap = array_combine($intoptions, $options);
		}
		$msg = "<header2>Predefined Options<end>\n";
		if (isset($optionsMap)) {
			foreach ($optionsMap as $key => $label) {
				$saveLink = Text::makeChatcmd('select', "/tell <myname> settings save {$this->row->name} {$key}");
				$msg .= '<tab><highlight>' . htmlspecialchars($label) . "<end> [{$saveLink}]\n";
			}
		} else {
			foreach ($options as $char) {
				$saveLink = Text::makeChatcmd('select', "/tell <myname> settings save {$this->row->name} {$char}");
				$msg .= '<tab><highlight>' . htmlspecialchars($char) . "<end> [{$saveLink}]\n";
			}
		}

		return $msg;
	}

	/** Change this setting to $newValue */
	public function save(string $newValue): string {
		return $newValue;
	}

	/** Get a description of the setting */
	abstract public function getDescription(): string;
}
