<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Botml;

use function Safe\mb_ord;
use League\CommonMark\Extension\Table\{Table, TableCell, TableRow, TableSection};
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\{ChildNodeRendererInterface, NodeRendererInterface};
use Nadybot\Core\Safe;

use Psr\Log\LoggerInterface;

class TableRenderer implements NodeRendererInterface {
	/** @var array<int,int> */
	private array $columnWidths = [];

	public function __construct(private LoggerInterface $logger) {
	}

	public function render(Node $node, ChildNodeRendererInterface $childRenderer): string {
		if ($node instanceof Table) {
			return $this->renderTable($node, $childRenderer);
		}

		if ($node instanceof TableRow) {
			return $this->renderTableRow($node, $childRenderer);
		}

		if ($node instanceof TableCell) {
			return $this->renderTableCell($node, $childRenderer);
		}

		throw new \InvalidArgumentException('Incompatible node type: ' . $node::class);
	}

	private function getCharWidth(string $text): int {
		if ($text === '') {
			return 0;
		}
		return match ($text) {
			' ' => 5,
			'0','1','2','3','4','5','6','7','8','9','_' => 8,
			'i','ï','í','ì','î','j','l' => 4,
			'f','t' => 5,
			'r' => 6,
			'c','ç','s','ş','z' => 7,
			'a','á','à','â','ä','å','b','d','e','é','è','ê','ë','g','h','k','n','ñ','o','ö','p','q','u','ü','v','x','y' => 8,
			'w' => 11,
			'm' => 13,
			'I' => 5,
			'J' => 6,
			'F','L' => 7,
			'E','É','È','Ê','P','T','Y','ß' => 8,
			'A','Ä','Á','À','Â','Å','B','C','K','R','S','V','X','Z' => 9,
			'D','G','H','N','Ñ','O','Ó','Ò','Ô','Ö','Q','U','Ú','Ù','Û','Ü' => 10,
			'M' => 11,
			'W' => 13,
			'\'' => 3,
			'!','.',',' => 5,
			'(',')','-','[',']','\\','|',':','"',';','/','„','“' => 6,
			'?','°','¹','²','³' => 7,
			'$','*','{','}','`','«','»','€','£' => 8,
			'&' => 9,
			'#','^','+','=','<','>','~','≈' => 11,
			'@','½','¼','⅓','¾' => 13,
			'%' => 14,
			default => -1, // Default width for other characters
		};
	}

	/** Return the length (in pixels) of the rendered text in AO */
	private function getRenderWidth(string $text): int {
		$width = 0;
		$text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5);
		$chars = Safe::pregSplit('//u', $text);
		foreach ($chars as $char) {
			$newWidth = $this->getCharWidth($char);
			if ($newWidth === -1) {
				$this->logger->warning("Character '{char}' ({code}) has an unknown length.", [
					'char' => $char,
					'code' => function_exists('mb_ord') ? mb_ord($char) : ord($char),
				]);
			}
			$width += $newWidth;
		}
		return $width;
	}

	private function renderTable(Table $node, ChildNodeRendererInterface $childRenderer): string {
		// Step 1: Analyze column width
		$this->analyzeTable($node, $childRenderer);

		// Step 2: Render table
		$rows = [];
		foreach ($node->children() as $child) {
			if ($child instanceof TableSection) {
				foreach ($child->children() as $rowChild) {
					if ($rowChild instanceof TableRow) {
						$rows[] = $this->renderTableRow($rowChild, $childRenderer, count($rows));
					}
				}
			}
		}

		// Reset for next table
		$this->columnWidths = [];

		return implode("\n", $rows) . "\n";
	}

	private function analyzeTable(Table $node, ChildNodeRendererInterface $childRenderer): void {
		$this->columnWidths = [];
		foreach ($node->children() as $sectionNode) {
			if (!($sectionNode instanceof TableSection)) {
				continue;
			}
			foreach ($sectionNode->children() as $rowNode) {
				if (!($rowNode instanceof TableRow)) {
					continue;
				}

				$colIndex = 0;
				foreach ($rowNode->children() as $cellNode) {
					if (!($cellNode instanceof TableCell)) {
						continue;
					}

					// Render cell and measure width
					$cellText = $this->renderCellContent($cellNode, $childRenderer);
					$width = $this->getRenderWidth($cellText);

					// Track max width per column
					if (!isset($this->columnWidths[$colIndex]) || $width > $this->columnWidths[$colIndex]) {
						$this->columnWidths[$colIndex] = $width;
					}

					$colIndex++;
				}
			}
		}
	}

	private function renderTableRow(TableRow $node, ChildNodeRendererInterface $childRenderer, int $rowIndex=-1): string {
		$cells = [];
		$colIndex = 0;

		foreach ($node->children() as $child) {
			if ($child instanceof TableCell) {
				$cells []= $this->renderTableCell($child, $childRenderer, $colIndex, $rowIndex);
				$colIndex++;
			}
		}

		if ($rowIndex === 0) {
			return '<u>' . implode('', $cells) . '</u>';
		}
		return implode('', $cells);
	}

	private function renderTableCell(TableCell $node, ChildNodeRendererInterface $childRenderer, int $colIndex=0, int $rowIndex=-1): string {
		$content = $this->renderCellContent($node, $childRenderer);
		$targetWidth = $this->columnWidths[$colIndex] ?? 0;
		$currentWidth = $this->getRenderWidth($content);
		$alignment = $node->getAlign();

		$result = $this->padText($content, $currentWidth, $targetWidth, $alignment);
		if ($colIndex > 0) {
			$result = ' | ' . $result; // Add column-separatore
		}
		if ($rowIndex === 0) {
			$result = '<highlight>' . $result . '<end>';
		}
		return $result;
	}

	private function renderCellContent(TableCell $node, ChildNodeRendererInterface $childRenderer): string {
		// Render content as plain text
		$rendered = $childRenderer->renderNodes($node->children());

		$rendered = strip_tags($rendered);

		return trim($rendered);
	}

	private function padText(string $text, int $currentWidth, int $targetWidth, ?string $alignment): string {
		if ($currentWidth >= $targetWidth) {
			return $text;
		}

		// Calculate missing amount of pixels
		$missingPixels = $targetWidth - $currentWidth;

		// Convert into spaces
		$spaceWidth = 5; // Assume each space is 5 pixels wide in AO
		$spacesNeeded = (int)ceil($missingPixels / $spaceWidth);

		$padding = str_repeat(' ', $spacesNeeded);

		switch ($alignment) {
			case TableCell::ALIGN_LEFT:
			case null:
				return $text . $padding;

			case TableCell::ALIGN_RIGHT:
				return $padding . $text;

			case TableCell::ALIGN_CENTER:
				$leftSpaces = (int)floor($spacesNeeded / 2);
				$rightSpaces = $spacesNeeded - $leftSpaces;
				return str_repeat(' ', $leftSpaces) . $text . str_repeat(' ', $rightSpaces);

			default:
				return $text . $padding;
		}
	}
}
