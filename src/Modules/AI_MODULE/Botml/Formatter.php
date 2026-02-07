<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Botml;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\{BlockQuote, FencedCode, Heading, ListBlock, ListItem, ThematicBreak};
use League\CommonMark\Extension\CommonMark\Node\Inline\{Code, Emphasis, Link, Strong};
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\MarkdownParser;
use Nadybot\Core\Safe;

class Formatter {
	private readonly MarkdownParser $parser;
	private readonly DocumentRenderer $documentRenderer;

	public function __construct() {
		$config = [
			'renderer' => [
				'block_separator' => "\n",
				'inner_separator' => "\n",
				'soft_break'      => "\n",
			],
			'commonmark' => [
				'enable_em' => true,
				'enable_strong' => true,
				'use_asterisk' => true,
				'use_underscore' => true,
				'unordered_list_markers' => ['-', '*', '+'],
			],
			'html_input' => 'escape',
			'allow_unsafe_links' => false,
			'max_nesting_level' => \PHP_INT_MAX,
			'max_delimiters_per_line' => \PHP_INT_MAX,
			'slug_normalizer' => [
				'max_length' => 255,
			],
			'table' => [
				'wrap' => [
					'enabled' => false,
					'tag' => 'div',
					'attributes' => [],
				],
				'alignment_attributes' => [
					'left'   => ['align' => 'left'],
					'center' => ['align' => 'center'],
					'right'  => ['align' => 'right'],
				],
			],
		];

		$environment = new Environment($config);
		$renderer = new Renderer();
		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addRenderer(Heading::class, $renderer);
		$environment->addRenderer(Emphasis::class, $renderer);
		$environment->addRenderer(Strong::class, $renderer);
		$environment->addRenderer(Paragraph::class, $renderer);
		$environment->addRenderer(ListBlock::class, $renderer);
		$environment->addRenderer(ListItem::class, $renderer);
		$environment->addRenderer(FencedCode::class, $renderer);
		$environment->addRenderer(Code::class, $renderer);
		$environment->addRenderer(BlockQuote::class, $renderer);
		$environment->addRenderer(Link::class, $renderer);
		$environment->addRenderer(ThematicBreak::class, $renderer);

		$this->parser = new MarkdownParser($environment);
		$this->documentRenderer = new DocumentRenderer($environment);
	}

	public function format(string $text): string {
		$document = $this->parser->parse($text);
		$lines = [];
		foreach ($document->iterator() as $node) {
			$line = str_repeat(' ', $node->getDepth());
			if ($node instanceof Text) {
				$line .= $node->getLiteral();
			} else {
				$line .= '(' . $node::class . ')';
			}
			$lines []= $line;
		}
		$rendered = $this->documentRenderer->renderDocument($document)->getContent();
		// Remove Emojis and other non-text characters that might cause issues in the chat
		$cleaned = Safe::pregReplace('/[\x{1F300}-\x{1F6FF}\x{1F700}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F1E0}-\x{1F1FF}]+/us', '', $rendered);
		return $cleaned;
	}
}
