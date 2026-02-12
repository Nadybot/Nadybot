<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Botml;

use League\CommonMark\Environment\EnvironmentInterface;
use League\CommonMark\Event\{DocumentPreRenderEvent, DocumentRenderedEvent};
use League\CommonMark\Node\Block\{AbstractBlock, Document};
use League\CommonMark\Node\Node;
use League\CommonMark\Output\{RenderedContent, RenderedContentInterface};
use League\CommonMark\Renderer\{ChildNodeRendererInterface, DocumentRendererInterface, NoMatchingRendererException};

/** @psalm-suppress DeprecatedInterface */
final class DocumentRenderer implements DocumentRendererInterface, ChildNodeRendererInterface {
	private readonly EnvironmentInterface $environment;

	public function __construct(EnvironmentInterface $environment) {
		$this->environment = $environment;
	}

	public function renderDocument(Document $document): RenderedContentInterface {
		$this->environment->dispatch(new DocumentPreRenderEvent($document, 'text'));

		$output = new RenderedContent($document, (string)$this->renderNode($document));

		$event = new DocumentRenderedEvent($output);
		$this->environment->dispatch($event);

		return $event->getOutput();
	}

	/** {@inheritDoc} */
	public function renderNodes(iterable $nodes): string {
		$output = '';

		$isFirstItem = true;

		foreach ($nodes as $node) {
			if (!$isFirstItem && $node instanceof AbstractBlock) {
				$output .= $this->getBlockSeparator();
			}

			$output .= (string)$this->renderNode($node);

			$isFirstItem = false;
		}

		return $output;
	}

	/**
	 * @psalm-suppress MixedReturnStatement
	 *
	 * @mago-ignore analysis:mixed-return-statement
	 */
	public function getBlockSeparator(): string {
		return $this->environment->getConfiguration()->get('renderer/block_separator');
	}

	/**
	 * @psalm-suppress MixedReturnStatement
	 *
	 * @mago-ignore analysis:mixed-return-statement
	 */
	public function getInnerSeparator(): string {
		return $this->environment->getConfiguration()->get('renderer/inner_separator');
	}

	/** @throws NoMatchingRendererException */
	private function renderNode(Node $node): \Stringable|string {
		$renderers = $this->environment->getRenderersForClass($node::class);

		foreach ($renderers as $renderer) {
			if (($result = $renderer->render($node, $this)) !== null) {
				return $result;
			}
		}

		throw new NoMatchingRendererException('Unable to find corresponding renderer for node type ' . $node::class);
	}
}
