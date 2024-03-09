<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use function Safe\json_decode;
use ParserGenerator\Parser;

use ParserGenerator\SyntaxTreeNode\Branch;

class RelayLayerExpressionParser {
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
			start   :=> layerList.
			layerList :=> layer
			             :=> layer layerList.
			layerName :=> /[a-zA-Z_0-9.:-]+/.
			layer :=> layerName "(" argumentList? ")".
			argumentList :=> argument
			             :=> argument "," argumentList.
			argument :=> key "=" value.
			key :=> layerName.
			value:int => -inf..inf
			     :bool => ("true"|"false")
			     :string => string
			     :simpleString => /[a-zA-Z_0-9]+/.
		';
	}

	/**
	 * @return RelayLayer[]
	 *
	 * @throws LayerParserException
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
			throw new LayerParserException(
				substr($input, 0, $posData['char']-1).
				"<red>" . (strlen($char) ? $char : "_") . "<end>".
				substr($input, $posData['char']) . "\n".
				"expected: <highlight>{$expected}<end>{$found}."
			);
		}
		$layers = $expr->findAll("layer");
		$result = [];
		foreach ($layers as $layer) {
			$result []= $this->parselayer($layer);
		}
		return $result;
	}

	protected function parselayer(Branch $layer): RelayLayer {
		$result = new RelayLayer();
		$result->layer = $layer->findFirst("layerName")->toString();
		foreach ($layer->findAll("argument") as $argument) {
			$result->arguments []= $this->parseArgument($argument);
		}
		return $result;
	}

	protected function parseArgument(Branch $argument): RelayLayerArgument {
		$result = new RelayLayerArgument();
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
