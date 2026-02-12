<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE;

use Nadybot\Core\{Attributes as NCA, Text};
use Nadybot\Core\SettingHandlers\SettingHandler;
use Nadybot\Modules\AI_MODULE\Attributes\Language;

/**
 * Class to represent an ai model setting
 */
#[NCA\SettingHandler(Language::TYPE)]
class LanguageSettingHandler extends SettingHandler {
	#[NCA\Inject]
	private TranslateController $translateController;

	/** {@inheritDoc} */
	public function getDescription(): string {
		$msg = "For this setting you need to enter the name of a supported language.\n\n".
			"To change this setting:\n\n".
			"<highlight>/tell <myname> settings save {$this->row->name} <i>model</i><end>\n\n";
		return $msg;
	}

	public function getOptions(): string {
		$msg = "<header2>Predefined Options<end>\n";
		foreach ($this->translateController->getLanguages() as $short => $long) {
			$saveLink = Text::makeChatcmd('select', "/tell <myname> settings save {$this->row->name} {$short}");
			$msg .= '<tab><highlight>' . htmlspecialchars($short) . "<end> ({$long}) [{$saveLink}]\n";
		}

		return $msg;
	}
}
