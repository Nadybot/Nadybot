<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Nadybot\Core\ParamClass\PProfession;
use ParserGenerator\Parser;
use ParserGenerator\SyntaxTreeNode\Branch;

class TrackerOnlineParser {
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
			start   :=> optionList.
			optionList :=> option
			             :=> option optionList.
			option :=> (titleLevelRange|titleLevel|all|edit|profession|faction).
			titleLevelRange :=> /tl[123456]-[2-7]/.
			titleLevel :=> /tl[1234567]/.
			faction :=> ("omni"|"clan"|"neutral"|"neut").
			all :=> "all".
			edit :=> "--edit".
			profession :=> /' . PProfession::getRegexp() . '/.
		';
	}

	/**
	 * @return TrackerOnlineOption[]
	 * @throws TrackerOnlineParserException
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
			throw new TrackerOnlineParserException(
				substr($input, 0, $posData['char']-1).
				"<red>" . (strlen($char) ? $char : "_") . "<end>".
				substr($input, $posData['char']) . "\n".
				"expected: <highlight>{$expected}<end>{$found}."
			);
		}
		$layers = $expr->findAll("option");
		$result = [];
		foreach ($layers as $layer) {
			$result []= $this->parseOption($layer);
		}
		return $result;
	}

	protected function parseOption(Branch $option): TrackerOnlineOption {
		$result = new TrackerOnlineOption();
		$result->type = $option->getSubnode(0)->getType();
		$result->value = $option->toString();
		return $result;
	}
}
