<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Botml;

use League\CommonMark\Extension\CommonMark\Node\Block\{BlockQuote, FencedCode, Heading, ListBlock, ListItem, ThematicBreak};
use League\CommonMark\Extension\CommonMark\Node\Inline\{Code, Emphasis, Link, Strong};
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\{ChildNodeRendererInterface, NodeRendererInterface};
use League\CommonMark\Util\Xml;

class Renderer implements NodeRendererInterface {
	/** @throws \InvalidArgumentException if the wrong type of Node is provided */
	public function render(Node $node, ChildNodeRendererInterface $childRenderer): string {
		return match (true) {
			$node instanceof Heading => $this->renderHeader($node, $childRenderer),
			$node instanceof Emphasis => $this->renderEmphasis($node, $childRenderer),
			$node instanceof Strong => $this->renderStrong($node, $childRenderer),
			$node instanceof Paragraph => $this->renderParagraph($node, $childRenderer),
			$node instanceof ListItem => $this->renderListItem($node, $childRenderer),
			$node instanceof FencedCode => $this->renderCode($node, $childRenderer),
			$node instanceof Code => $this->renderInlineCode($node, $childRenderer),
			$node instanceof BlockQuote => $this->renderBlockQuote($node, $childRenderer),
			$node instanceof Link => $this->renderLink($node, $childRenderer),
			$node instanceof ThematicBreak => $this->renderBreak($node, $childRenderer),
			default => $childRenderer->renderNodes($node->children()),
		};
	}

	public function renderBreak(ThematicBreak $node, ChildNodeRendererInterface $childRenderer): string {
		return str_repeat('_', 50) . "\n";
	}

	public function renderHeader(Heading $node, ChildNodeRendererInterface $childRenderer): string {
		return '<header2>' . $childRenderer->renderNodes($node->children()) . '<end>';
	}

	public function renderEmphasis(Emphasis $node, ChildNodeRendererInterface $childRenderer): string {
		return '<i>' . $childRenderer->renderNodes($node->children()) . '</i>';
	}

	public function renderStrong(Strong $node, ChildNodeRendererInterface $childRenderer): string {
		return '<highlight>' . $childRenderer->renderNodes($node->children()) . '<end>';
	}

	public function renderLink(Link $node, ChildNodeRendererInterface $childRenderer): string {
		return "<a href={$node->getUrl()}>" . $childRenderer->renderNodes($node->children()) . '</a>';
	}

	public function renderParagraph(Paragraph $node, ChildNodeRendererInterface $childRenderer): string {
		if ($node->parent() instanceof ListItem) {
			return $childRenderer->renderNodes($node->children());
		}
		return $childRenderer->renderNodes($node->children()) . "\n";
	}

	public function renderListItem(ListItem $node, ChildNodeRendererInterface $childRenderer): string {
		$depth = 0;

		/** @var ?Node */
		$parent = $node->parent();
		while ($parent !== null) {
			if ($parent instanceof ListBlock) {
				$depth++;
			}

			/** @var ?Node */
			$parent = $parent->parent();
		}

		/** @var ListBlock */
		$parent = $node->parent();
		ListBlock::assertInstanceOf($parent);

		$parent->getDepth();
		$type = $parent->getListData()->type;
		$tag = $type === ListBlock::TYPE_BULLET ? '*' : '-';

		return str_repeat('<tab>', $depth).
			$tag . ' ' . trim($childRenderer->renderNodes($node->children()));
	}

	public function renderCode(FencedCode $node, ChildNodeRendererInterface $childRenderer): string {
		$code = Xml::escape($node->getLiteral());
		$lines = explode("\n", trim($code));
		$code = '<tab>' . implode("\n<tab>", $lines);
		return '<highlight>' . $code . "<end>\n";
	}

	public function renderInlineCode(Code $node, ChildNodeRendererInterface $childRenderer): string {
		$code = Xml::escape($node->getLiteral());
		return '<highlight>' . $code . '<end>';
	}

	public function renderBlockQuote(BlockQuote $node, ChildNodeRendererInterface $childRenderer): string {
		$content = $childRenderer->renderNodes($node->children());
		$lines = explode("\n", trim($content));
		return '<tab>&gt; ' . implode("\n<tab>&gt;", $lines);
	}
}
