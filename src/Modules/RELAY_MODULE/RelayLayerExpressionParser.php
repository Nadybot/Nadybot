<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use function Safe\json_decode;

use Exception;
use ParserGenerator\Parser;

use ParserGenerator\SyntaxTreeNode\Branch;

class RelayLayerExpressionParser {
	protected ?Parser $parser=null;

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
	 * @return list<RelayLayer>
	 *
	 * @throws LayerParserException
	 */
	public function parse(RelayConfig $relay, string $input): array {
		$parser = $this->getParser();
		$expr = $parser->parse($input);
		if ($expr === false) {
			$error = $parser->getError();
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
			throw new LayerParserException(
				substr($input, 0, $posData['char']-1).
				'<red>' . (strlen($char) ? $char : '_') . '<end>'.
				substr($input, $posData['char']) . "\n".
				"expected: <highlight>{$expected}<end>{$found}."
			);
		}
		$layers = $expr->findAll('layer');
		$result = [];
		foreach ($layers as $layer) {
			$result []= $this->parselayer($relay, $layer);
		}
		return $result;
	}

	protected function parselayer(RelayConfig $relay, Branch $layer): RelayLayer {
		$layerName = $layer->findFirst('layerName')?->toString();
		if (!isset($layerName)) {
			throw new Exception('Invalid Relay Layer structure');
		}
		$result = new RelayLayer(
			relay_id: $relay->id,
			layer: $layerName,
			arguments: [],
		);
		foreach ($layer->findAll('argument') as $argument) {
			$result->arguments []= $this->parseArgument($result, $argument);
		}
		return $result;
	}

	protected function parseArgument(RelayLayer $layer, Branch $argument): RelayLayerArgument {
		$name = $argument->findFirst('key')?->toString();
		$value = $argument->findFirst('value');
		if (!isset($name) || !isset($value)) {
			throw new Exception('Invalid Relay Layer structure');
		}
		if ($value->getDetailType() === 'string') {
			$value = json_decode($value->toString());
		} else {
			$value = $value->toString();
		}
		$result = new RelayLayerArgument(
			layer_id: $layer->id,
			name: $name,
			value: $value
		);
		return $result;
	}
}
