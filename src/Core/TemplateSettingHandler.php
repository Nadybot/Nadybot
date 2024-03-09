<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Nadybot\Core\Attributes as NCA;
use ReflectionAttribute;
use ReflectionObject;

/**
 * Class to represent a setting with a template value for NadyBot
 */
#[NCA\SettingHandler("template")]
class TemplateSettingHandler extends SettingHandler {
	/** Get a displayable representation of the setting */
	public function displayValue(string $sender): string {
		$examples = [];
		$attr = $this->getAttribute();
		if (isset($attr, $attr->exampleValues)) {
			$examples = $attr->exampleValues;
		}
		return $this->text->renderPlaceholders($this->row->value??"", $examples);
	}

	/** Get all options for this setting or null if no options are available */
	public function getOptions(): ?string {
		$examples = [];
		$attr = $this->getAttribute();
		if (isset($attr, $attr->exampleValues)) {
			$examples = $attr->exampleValues;
		}

		if (strlen($this->row->options??'')) {
			$options = explode(";", $this->row->options??"");
		}
		if (strlen($this->row->intoptions??'')) {
			$intoptions = explode(";", $this->row->intoptions??"");
			$options_map = array_combine($intoptions, $options??[]);
		}
		if (empty($options)) {
			return null;
		}
		$msg = "<header2>Predefined Values<end>\n";
		if (isset($options_map)) {
			foreach ($options_map as $key => $label) {
				$saveLink = $this->text->makeChatcmd('select', "/tell <myname> settings save {$this->row->name} {$key}");
				$label = htmlspecialchars($label);
				$label = implode("<end>/<highlight>", explode("/", $label));
				$msg .= "<tab><highlight>{$label}<end> [{$saveLink}]\n";
				$msg .= "<tab>{$key}\n\n";
			}
		} else {
			foreach ($options as $char) {
				$saveLink = $this->text->makeChatcmd(
					'select',
					"/tell <myname> settings save {$this->row->name} ".
					htmlentities($char)
				);
				$char = $this->text->renderPlaceholders($char, $examples);
				$char = join("\n<tab>", explode("\n", $char));
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
	 * @throws \Exception when the string is not a valid HTML color
	 */
	public function save(string $newValue): string {
		if (strlen($newValue) > 255) {
			throw new Exception("Your text can not be longer than 255 characters.");
		}
		$colors = $this->text->getColors();
		$colorNames = join(
			"|",
			array_map(
				fn (string $tag): string => preg_quote(substr($tag, 1, -1), "/"),
				[...array_keys($colors), "<myname>", "<end>", "<i>", "<u>", "</i>", "</u>"]
			)
		);
		$newValue = preg_replace("/&lt;({$colorNames})&gt;/", '<$1>', $newValue);
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
					/** @var NCA\Setting\Template */
					$attr = $refAttr->newInstance();
					$attr->name ??= Nadybot::toSnakeCase($refProp->getName());
					if ($attr->name === $this->row->name) {
						return $attr;
					}
				}
			}
		}
		return null;
	}
}
