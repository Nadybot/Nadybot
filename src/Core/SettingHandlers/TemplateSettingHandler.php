<?php declare(strict_types=1);

namespace Nadybot\Core\SettingHandlers;

use Exception;
use Nadybot\Core\{Attributes as NCA, ModuleInstance, Registry, Safe, Text};
use ReflectionAttribute;

use ReflectionObject;

/**
 * Class to represent a setting with a template value for Nadybot
 */
#[NCA\SettingHandler('template')]
class TemplateSettingHandler extends SettingHandler {
	#[NCA\Inject]
	private Text $text;

	/** {@inheritDoc} */
	public function displayValue(string $sender): string {
		$examples = [];
		$attr = $this->getAttribute();
		if (isset($attr, $attr->exampleValues)) {
			$examples = $attr->exampleValues;
		}
		return Text::renderPlaceholders($this->row->value??'', $examples);
	}

	/** {@inheritDoc} */
	public function getOptions(): ?string {
		$examples = [];
		$attr = $this->getAttribute();
		if (isset($attr, $attr->exampleValues)) {
			$examples = $attr->exampleValues;
		}

		if (!strlen($this->row->options??'')) {
			return null;
		}
		$options = explode(';', $this->row->options??'');
		if (strlen($this->row->intoptions??'')) {
			$intOptions = explode(';', $this->row->intoptions??'');
			$optionsMap = array_combine($intOptions, $options);
		}
		$msg = "<header2>Predefined Values<end>\n";
		if (isset($optionsMap)) {
			foreach ($optionsMap as $key => $label) {
				$saveLink = Text::makeChatcmd('select', "/tell <myname> settings save {$this->row->name} {$key}");
				$label = htmlspecialchars($label);
				$label = implode('<end>/<highlight>', explode('/', $label));
				$msg .= "<tab><highlight>{$label}<end> [{$saveLink}]\n";
				$msg .= "<tab>{$key}\n\n";
			}
		} else {
			foreach ($options as $char) {
				$saveLink = Text::makeChatcmd(
					'select',
					"/tell <myname> settings save {$this->row->name} ".
					htmlentities($char)
				);
				$char = Text::renderPlaceholders($char, $examples);
				$char = implode("\n<tab>", explode("\n", $char));
				$msg .= "<tab>{$char} [{$saveLink}]\n";
			}
		}

		return $msg;
	}

	/** Describe the valid values for this setting */
	public function getDescription(): string {
		$msg = "For this setting you can enter any text you want (max. 255 characters).\n".
			"The text will have tokens set inside curly brackets parsed (see below)\n".
			"To change this setting:\n\n".
			"<highlight>/tell <myname> settings save {$this->row->name} <i>text</i><end>\n\n";
		return $msg;
	}

	/**
	 * Change this setting
	 *
	 * @throws \Exception if the string is longer than 255 characters
	 */
	public function save(string $newValue): string {
		if (strlen($newValue) > 255) {
			throw new Exception('Your text can not be longer than 255 characters.');
		}
		$colors = $this->text->getColors();
		$colorNames = implode(
			'|',
			array_map(
				static fn (string $tag): string => preg_quote(substr($tag, 1, -1), '/'),
				[...array_keys($colors), '<myname>', '<end>', '<i>', '<u>', '</i>', '</u>']
			)
		);
		$newValue = Safe::pregReplace("/&lt;({$colorNames})&gt;/", '<$1>', $newValue);
		return $newValue;
	}

	private function getAttribute(): ?NCA\Setting\Template {
		$instances = Registry::getAllInstances();
		foreach ($instances as $instance) {
			if (!($instance instanceof ModuleInstance)) {
				continue;
			}
			if ($instance->getModuleName() !== $this->row->module) {
				continue;
			}
			$refObj = new ReflectionObject($instance);
			foreach ($refObj->getProperties() as $refProp) {
				foreach ($refProp->getAttributes(NCA\Setting\Template::class, ReflectionAttribute::IS_INSTANCEOF) as $refAttr) {
					$attr = $refAttr->newInstance();
					$attr->name ??= Text::toSnakeCase($refProp->getName());
					if ($attr->name === $this->row->name) {
						return $attr;
					}
				}
			}
		}
		return null;
	}
}
