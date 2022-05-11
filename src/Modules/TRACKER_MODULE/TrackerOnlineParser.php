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
			option :=> (titleLevelRange|titleLevel|levelRange|level|all|edit|profession|faction).
			titleLevelRange :=> /tl[123456]-[2-7]/.
			titleLevel :=> /tl[1234567]/.
			levelRange :=> (1..220 "-" 1..220 | 1..220 "-" | "-" 1..220).
			level :=> 1..220.
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

			$wordStart = strrpos($input, " ", -1 * (strlen($input) - $error['index']));
			if ($wordStart === false) {
				$wordStart = -1;
			}
			$wordEnd = strpos($input, " ", $error['index']);
			if ($wordEnd === false) {
				$wordEnd = strlen($input) + 1;
			}
			$found = substr($input, $wordStart+1, $wordEnd - $wordStart - 1);
			throw new TrackerOnlineParserException(
				"'<highlight>{$found}<end>' ".
				"is not a valid filter criteria."
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
