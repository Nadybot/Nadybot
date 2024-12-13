<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use MathParser\Exceptions\UnknownOperatorException;
use MathParser\Parsing\Nodes\Factories\NodeFactory;
use MathParser\Parsing\Nodes\{ExpressionNode, Node};

class AONodeFactory extends NodeFactory {
	/**
	 * Simplify the given ExpressionNode, using the appropriate factory.
	 *
	 * @return Node Simplified version of the input
	 */
	public function simplify(ExpressionNode $node): Node {
		$left = $node->getLeft();
		$right = $node->getRight();
		return match ($node->getOperator()) {
			'+' => $node,
			'-' => $node,
			'*' => $node,
			'/' => isset($right, $left) ? $this->division($left, $right) : $node,
			'^' => $node,
			default => throw new UnknownOperatorException($node->getOperator()),
		};
	}
}
