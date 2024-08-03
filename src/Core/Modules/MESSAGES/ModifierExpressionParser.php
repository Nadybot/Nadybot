<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\MESSAGES;

use function Safe\json_decode;
use Nadybot\Core\DBSchema\{Route, RouteModifier, RouteModifierArgument};
use ParserGenerator\Parser;

use ParserGenerator\SyntaxTreeNode\{Branch, Root};

class ModifierExpressionParser {
	private ?Parser $parser=null;

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
	 * @return list<RouteModifier>
	 *
	 * @throws ModifierParserException
	 */
	public function parse(Route $route, string $input): array {
		$parser = $this->getParser();

		/** @var Root|false */
		$expr = $parser->parse($input);
		if ($expr === false) {
			$error = $parser->getError();

			/** @var array{"line":int,"char":int} */
			$posData = $parser::getLineAndCharacterFromOffset($input, $error['index']);

			$expected = implode('<end> or <highlight>', $parser->generalizeErrors($error['expected']));
			$foundLength = 20;
			$found = substr($input, $error['index']);
			if (strlen($found) > $foundLength) {
				$found = substr($found, 0, $foundLength) . '...';
			}

			$char = substr($input, $posData['char']-1, 1);
			if ($found !== '') {
				$found = ", found: <highlight>\"{$found}\"<end>";
			}
			throw new ModifierParserException(
				substr($input, 0, $posData['char']-1).
				'<red>' . (strlen($char) ? $char : '_') . '<end>'.
				substr($input, $posData['char']) . "\n".
				"expected: <highlight>{$expected}<end>{$found}."
			);
		}
		$modifiers = $expr->findAll('modifier');
		$result = [];
		foreach ($modifiers as $modifier) {
			$result []= $this->parseModifier($route, $modifier);
		}
		return $result;
	}

	protected function parseModifier(Route $route, Branch $modifier): RouteModifier {
		$modifierName = $modifier->findFirst('modifierName')?->toString();
		if (!isset($modifierName)) {
			throw new \Exception('Invalid expression structure');
		}
		$routeModifier = new RouteModifier(
			route_id: $route->id,
			modifier: $modifierName,
			arguments: [],
		);
		foreach ($modifier->findAll('argument') as $argument) {
			$routeModifier->arguments []= $this->parseArgument($routeModifier, $argument);
		}
		return $routeModifier;
	}

	protected function parseArgument(RouteModifier $routeModifier, Branch $argument): RouteModifierArgument {
		$name = $argument->findFirst('key')?->toString();
		$value = $argument->findFirst('value');
		if (!isset($name) || !isset($value)) {
			throw new \Exception('Invalid modifier expression');
		}
		if ($value->getDetailType() === 'string') {
			$value = json_decode($value->toString());
		} else {
			$value = $value->toString();
		}
		$result = new RouteModifierArgument(
			name: $name,
			value: $value,
			route_modifier_id: $routeModifier->id,
		);
		return $result;
	}
}
