<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Exception;
use MathParser\Exceptions\UnknownVariableException;
use MathParser\Interpreting\Evaluator;
use MathParser\Parsing\Parser;
use MathParser\StdMathParser;
use Nadybot\Core\Attributes\Str;
use Nadybot\Core\ParamClass\{PRemove, PWord};
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Safe,
	Text,
};
use Throwable;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/Formula'),
	NCA\DefineCommand(
		command: 'calc',
		accessLevel: 'guest',
		description: 'Calculator',
	),
	NCA\DefineCommand(
		command: FormulaController::FORMULA_MODIFY,
		accessLevel: 'member',
		description: 'Create and delete formulas',
	),
	NCA\DefineCommand(
		command: FormulaController::FORMULA,
		accessLevel: 'guest',
		description: 'List and execute formulas',
	)
]
class FormulaController extends ModuleInstance {
	public const FORMULA_MODIFY = 'formula modify';
	public const FORMULA = 'formula';

	/** How to show multiplications in calculations */
	#[NCA\Setting\Text(
		options: ['*', '×', '•']
	)]
	public string $multiplicationSign = '×';

	/** How to show divisions in calculations */
	#[NCA\Setting\Text(
		options: ['/', ':', '÷']
	)]
	public string $divisionSign = '/';

	/** How to show divisions in calculations */
	#[NCA\Setting\Boolean]
	public bool $simplifyFormula = true;

	#[NCA\Inject]
	private DB $db;

	/** Use a calculator */
	#[NCA\Help\Prologue(
		'Nadybot has a complete math parser capable of parsing anything from '.
		'a simple equation like 1+1 up to evaluating well-known constants like '.
		"pi, or e.\n".
		"It also implements some functions to make things easier:\n".
		'sin(), cos(), and the likes, log10()/lg(), log()/ln(), abs(), sqrt(), '.
		"round(), ceil(), floor(), and sgn().\n".
		'Check the Wiki for a complete description.'
	)]
	#[NCA\HandlesCommand('calc')]
	#[NCA\Help\Example('<symbol>calc 1+1')]
	#[NCA\Help\Example('<symbol>calc 2^16')]
	#[NCA\Help\Example('<symbol>calc 4/3*pi*17^3')]
	public function calcCommand(CmdContext $context, string $formula): void {
		$parser = new StdMathParser();
		$parser->parser = new Parser(new AONodeFactory());
		$parser->setSimplifying(true);
		$tree = $parser->parse($formula);
		try {
			$evaluator = new Evaluator([]);
			$result = (float)$tree->accept($evaluator);
			$printer = new AOPrinter($this);
			$formula = (string)$tree->accept($printer);
		} catch (UnknownVariableException) {
			$context->reply('Variables are not supported. Use <highlight><symbol>formula<end> for variables.');
			return;
		} catch (Exception $e) {
			$context->reply("Cannot compute: {$e->getMessage()}");
			return;
		}
		$result = $this->resultToString($result);

		$context->reply("{$formula} = <highlight>{$result}<end>");
	}

	/** Store a new formula */
	#[NCA\HandlesCommand(self::FORMULA_MODIFY)]
	#[NCA\Help\Example('<symbol>formula add binomic x^2 + 2xy + y^2')]
	public function formulaAddCommand(
		CmdContext $context,
		#[Str('add')] string $subCommand,
		PWord $name,
		string $formula
	): void {
		$name = $name();
		if (strlen($name) > 20) {
			$context->reply('The maximum length of a formula\'s length is 20 characters.');
			return;
		}
		if ($this->db->table(Formula::getTable())->where('name', $name)->exists()) {
			$context->reply("A formula with the name <highlight>{$name}<end> already exists.");
			return;
		}
		$parser = new StdMathParser();
		$parser->parser = new Parser(new AONodeFactory());
		$parser->setSimplifying(true);
		try {
			$tree = $parser->parse($formula);
		} catch (Exception $e) {
			$context->reply($e->getMessage());
			return;
		}
		try {
			$printer = new AOPrinter($this);
			$prettyPrint = (string)$tree->accept($printer);
		} catch (Throwable) {
			$prettyPrint = $formula;
		}
		$this->db->insert(new Formula(
			name: $name,
			formula: $formula
		));
		$context->reply(
			"New formula <highlight>{$prettyPrint}<end> added as <highlight>{$name}<end>.\n".
			"Use <highlight><symbol>formula solve {$name}<end> to solve it."
		);
	}

	/** Solve a stored formula */
	#[NCA\HandlesCommand(self::FORMULA)]
	#[NCA\Help\Example('<symbol>formula solve binomic x=2 y=10')]
	public function formulaRunCommand(
		CmdContext $context,
		#[Str('solve', 'use', 'run', 'exec')] string $subCommand,
		PWord $formulaName,
		#[Str('for')] ?string $for='for',
		#[NCA\Regexp("\w+=\w+", example: '&lt;variable&gt;=&lt;value&gt;')] ?string ...$variables
	): void {
		$name = $formulaName();
		$formula = $this->db->table(Formula::getTable())
			->where('name', $name)
			->firstObj(Formula::class);
		if (!isset($formula)) {
			$context->reply("No formula <highlight>{$name}<end> found.");
			return;
		}
		$variableList = Safe::removeNull(array_values($variables));
		$variables = [];
		$paramList = [];
		foreach ($variableList as $variablePair) {
			[$key, $value] = explode('=', $variablePair);
			$variables[$key] = $value;
			$paramList []= "<cyan>{$key}<end>=<highlight>{$value}<end>";
		}
		$parser = new StdMathParser();
		$parser->parser = new Parser(new AONodeFactory());
		$parser->setSimplifying(true);
		try {
			$tree = $parser->parse($formula->formula);
			$evaluator = new Evaluator($variables);
			$result = (float)$tree->accept($evaluator);
			$printer = new AOPrinter($this);
			$prettyPrint = (string)$tree->accept($printer);
		} catch (UnknownVariableException $e) {
			$context->reply(
				"Missing variable <highlight>{$e->getVariable()}<end>. ".
				"Please specify with <highlight><symbol>{$context->message} {$e->getVariable()}=&lt;value&gt;<end>."
			);
			return;
		} catch (Exception $e) {
			$context->reply('Cannot compute: ' . $e->getMessage());
			return;
		}
		$result = $this->resultToString($result);

		$reply = "{$prettyPrint} = <highlight>{$result}<end>";
		if (count($paramList) > 0) {
			$reply .= "\nFor " . Text::enumerate(...$paramList) . '.';
		}
		$context->reply($reply);
	}

	/** Remove a stored new formula */
	#[NCA\HandlesCommand(self::FORMULA_MODIFY)]
	#[NCA\Help\Example('<symbol>formula rem binomic')]
	public function formulaDelCommand(
		CmdContext $context,
		PRemove $subAction,
		PWord $name,
	): void {
		$name = $name();
		$numDeleted = $this->db->table(Formula::getTable())
			->where('name', $name)
			->delete();
		if ($numDeleted === 0) {
			$context->reply("No formula <highlight>{$name}<end> found.");
			return;
		}
		$context->reply("Formula <highlight>{$name}<end> deleted.");
	}

	/** List all stored formulas */
	#[NCA\HandlesCommand(self::FORMULA)]
	public function formulaListCommand(
		CmdContext $context,
		#[Str('list')] ?string $subCommand=null
	): void {
		$formulas = $this->db->table(Formula::getTable())
			->asObj(Formula::class)
			->map($this->renderFormula(...));
		if ($formulas->isEmpty()) {
			$context->reply('No formulas found.');
			return;
		}
		$blob = Text::makeBlob(
			name: "Formulas ({$formulas->count()})",
			content: "<header2>Stored Formulas<end>\n<tab>".
				$formulas->join("\n<tab>")."\n\n".
				'<i>To solve a formula, use <highlight><symbol>formula solve &lt;name&gt;<end>.</i>'
		);
		$context->reply($blob);
	}

	/** Render a formula pretty (if possible) */
	private function renderFormula(Formula $formula): string {
		$parser = new StdMathParser();
		$parser->parser = new Parser(new AONodeFactory());
		$parser->setSimplifying(true);
		try {
			$tree = $parser->parse($formula->formula);
			$printer = new AOPrinter($this);
			$prettyPrint = (string)$tree->accept($printer);
		} catch (Throwable) {
			$prettyPrint = $formula->formula;
		}
		return "<highlight>{$formula->name}<end>: {$prettyPrint}";
	}

	private function resultToString(float $result): string {
		if ($result === \INF) {
			return '∞';
		}
		$result = Safe::pregReplace("/\.?0+$/", '', number_format(round($result, 4), 4));
		return str_replace(',', '<end>,<highlight>', $result);
	}
}
