<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\MESSAGES;

use Nadybot\Core\DBSchema\RouteModifier;
use Nadybot\Core\DBSchema\RouteModifierArgument;
use ParserGenerator\Parser;
use ParserGenerator\SyntaxTreeNode\Branch;

class ModifierExpressionParser {
	protected Parser $parser;

	public function getParser(): Parser {
		if (!isset($this->parser)) {
			$this->parser = new Parser(
				$this->getExpressionDefinition(),
				['ignoreWhitespaces' => true]
			);
		}
		return $this->parser;
	}

	public function getExpressionDefinition(): string {
		return '
			start   :=> modifierList.
			modifierList :=> modifier
			             :=> modifier modifierList.
			modifierName :=> /[a-zA-Z_0-9.:-]+/.
			modifier :=> modifierName "(" argumentList? ")".
			argumentList :=> argument
			             :=> argument "," argumentList.
			argument :=> key "=" value.
			key :=> modifierName.
			value:int => -inf..inf
			     :bool => ("true"|"false")
			     :string => string
			     :simpleString => /[a-zA-Z_0-9]+/.
		';
	}

	/**
	 * @return RouteModifier[]
	 * @throws ModifierParserException
	 */
	public function parse(string $input): array {
		$parser = $this->getParser();
		$expr = $parser->parse($input);
		if ($expr === false) {
			$error = $parser->getError();
			$posData = $this->parser::getLineAndCharacterFromOffset($input, $error['index']);

			$expected = implode('<end> or <highlight>', $this->parser->generalizeErrors($error['expected']));
			$foundLength = 20;
			$found = substr($input, $error['index']);
			if (strlen($found) > $foundLength) {
				$found = substr($found, 0, $foundLength) . '...';
			}

			$char = substr($input, $posData['char']-1, 1);
			if ($found !== "") {
				$found = ", found: <highlight>\"{$found}\"<end>";
			}
			throw new ModifierParserException(
				substr($input, 0, $posData['char']-1).
				"<red>" . (strlen($char) ? $char : "_") . "<end>".
				substr($input, $posData['char']) . "\n".
				"expected: <highlight>{$expected}<end>{$found}."
			);
		}
		$modifiers = $expr->findAll("modifier");
		$result = [];
		foreach ($modifiers as $modifier) {
			$result []= $this->parseModifier($modifier);
		}
		return $result;
	}

	protected function parseModifier(Branch $modifier): RouteModifier {
		$result = new RouteModifier();
		$result->modifier = $modifier->findFirst("modifierName")->toString();
		foreach ($modifier->findAll("argument") as $argument) {
			$result->arguments []= $this->parseArgument($argument);
		}
		return $result;
	}

	protected function parseArgument(Branch $argument): RouteModifierArgument {
		$result = new RouteModifierArgument();
		$result->name = $argument->findFirst("key")->toString();
		$value = $argument->findFirst("value");
		if ($value->getDetailType() === 'string') {
			$result->value = json_decode($value->toString());
		} else {
			$result->value = $value->toString();
		}
		return $result;
	}
}
