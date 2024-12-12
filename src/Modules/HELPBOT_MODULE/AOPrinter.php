<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use MathParser\Exceptions\{SyntaxErrorException, UnknownOperatorException};
use MathParser\Interpreting\Visitors\VisitorInterface;
use MathParser\Parsing\Nodes\{ConstantNode, ExpressionNode, FunctionNode, IntegerNode, Node, NumberNode, RationalNode, VariableNode};

/**
 * Create AO code for prettyprinting a mathematical expression
 *
 * Implementation of a Visitor, transforming an AST into a string
 */
class AOPrinter implements VisitorInterface {
	/**
	 * Generate AO HTML output code for an ExpressionNode
	 *
	 * Create a string giving ASCII output representing an ExpressionNode `(x op y)`
	 * where `op` is one of `+`, `-`, `*`, `/` or `^`
	 *
	 * @param ExpressionNode $node AST to be typeset
	 */
	public function visitExpressionNode(ExpressionNode $node): string {
		$operator = $node->getOperator();
		$left = $node->getLeft();
		$right = $node->getRight();

		switch ($operator) {
			case '+':
				if (!isset($left) || !isset($right)) {
					throw new SyntaxErrorException();
				}
				$leftValue = $left->accept($this);
				$rightValue = $this->parenthesize($right, $node);
				return "{$leftValue} + {$rightValue}";

			case '-':
				if (isset($right) && isset($left)) {
					// Binary minus

					$leftValue = $left->accept($this);
					$rightValue = $this->parenthesize($right, $node);
					return "{$leftValue} - {$rightValue}";
				} elseif (isset($left)) {
					// Unary minus
					$leftValue = $this->parenthesize($left, $node);
					return "-{$leftValue}";
				}
				throw new SyntaxErrorException();


			case '/':
				if (!isset($left) || !isset($right)) {
					throw new SyntaxErrorException();
				}
				$leftValue = $this->parenthesize($left, $node, '', false);
				$rightValue = $this->parenthesize($right, $node, '', true);
				return "{$leftValue} {$operator} {$rightValue}";

			case '*':
				if (!isset($left) || !isset($right)) {
					throw new SyntaxErrorException();
				}
				$leftValue = $this->parenthesize($left, $node, '', false);
				$rightValue = $this->parenthesize($right, $node, '', true);
				return "{$leftValue} × {$rightValue}";

			case '^':
				if (!isset($left) || !isset($right)) {
					throw new SyntaxErrorException();
				}
				$leftValue = $this->parenthesize($left, $node, '', true);
				$rightValue = $this->parenthesize($right, $node, '', false);
				return "{$leftValue}{$operator}{$rightValue}";

			default:
				throw new UnknownOperatorException($operator);
		}
	}

	public function visitNumberNode(NumberNode $node): string {
		$val = $node->getValue();

		return "<cyan>{$val}<end>";
	}

	public function visitIntegerNode(IntegerNode $node): string {
		$val = $node->getValue();

		return "<cyan>{$val}<end>";
	}

	public function visitRationalNode(RationalNode $node): string {
		$p = $node->getNumerator();
		$q = $node->getDenominator();
		if ($q === 1.0) {
			return "<cyan>{$p}<end>";
		}

		return "<cyan>{$p}/{$q}<end>";
	}

	public function visitVariableNode(VariableNode $node): string {
		return "<cyan>{$node->getName()}<end>";
	}

	public function visitFunctionNode(FunctionNode $node): string {
		$functionName = $node->getName();

		if ($functionName === '!' || $functionName === '!!') {
			return $this->visitFactorialNode($node);
		}

		$operand = $node->getOperand()?->accept($this);
		if (!isset($operand)) {
			throw new SyntaxErrorException();
		}
		switch ($functionName) {
			case 'sqrt':
				$functionName = '√';
		}

		return "<cyan>{$functionName}<end>({$operand})";
	}

	public function visitConstantNode(ConstantNode $node): string {
		$name = $node->getName();
		switch ($name) {
			case 'pi':
				$name = 'π';
				break;
			case 'INF':
				$name = '∞';
				break;
		}
		return "<cyan>{$name}<end>";
	}

	public function parenthesize(Node $node, ExpressionNode $cutoff, string $prepend='', bool $conservative=false): string {
		$text = $node->accept($this);

		if ($node instanceof ExpressionNode) {
			// Second term is a unary minus
			if ($node->getOperator() === '-' && $node->getRight() === null) {
				return "({$text})";
			}

			if ($cutoff->getOperator() === '-' && $node->lowerPrecedenceThan($cutoff)) {
				return "({$text})";
			}

			if ($conservative) {
				// Add parentheses more liberally for / and ^ operators,
				// so that e.g. x/(y*z) is printed correctly
				if ($cutoff->getOperator() === '/' && $node->lowerPrecedenceThan($cutoff)) {
					return "({$text})";
				}
				if ($cutoff->getOperator() === '^' && $node->getOperator() === '^') {
					return "({$text})";
				}
			}

			if ($node->strictlyLowerPrecedenceThan($cutoff)) {
				return "({$text})";
			}
		}

		if (($node instanceof NumberNode || $node instanceof IntegerNode || $node instanceof RationalNode) && $node->getValue() < 0) {
			return "({$text})";
		}

		// Treat rational numbers as divisions on printing
		if ($node instanceof RationalNode && $node->getDenominator() !== 1.0) {
			$fakeNode = new ExpressionNode($node->getNumerator(), '/', $node->getDenominator());

			if ($fakeNode->lowerPrecedenceThan($cutoff)) {
				return "({$text})";
			}
		}

		return "{$prepend}{$text}";
	}

	private function visitFactorialNode(FunctionNode $node): string {
		$functionName = $node->getName();
		$op = $node->getOperand();
		if (!isset($op)) {
			throw new SyntaxErrorException();
		}
		$operand = $op->accept($this);

		// Add parentheses most of the time.
		if ($op instanceof NumberNode || $op instanceof IntegerNode || $op instanceof RationalNode) {
			if ($op->getValue() < 0) {
				$operand = "({$operand})";
			}
		} elseif ($op instanceof VariableNode || $op instanceof ConstantNode) {
			// Do nothing
		} else {
			$operand = "({$operand})";
		}

		return "{$operand}{$functionName}";
	}
}
