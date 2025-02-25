<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

use InvalidArgumentException;
use Nadybot\Core\Safe;
use Nadybot\Core\Types\AOItem;

/**
 * This allows a pasted item to be given as parameter
 * The result will always be a properly unescaped item string,
 * but because this class implements AOItem, it can be used directly
 * when interacting with other functions.
 */
class PItem extends Base implements AOItem {
	/** The low ID of the item */
	public int $lowID;

	/** The high ID of the item */
	public int $highID;

	/** The QL of the item */
	public int $ql;

	/** The name of the item, as given in the chat */
	public string $name;
	protected static string $regExp = "(?:<|&lt;)a href=(?:&#39;|'|\x22)itemref://\d+/\d+/\d+(?:&#39;|'|\x22)(?:>|&gt;).+?(<|&lt;)/a(>|&gt;)";
	protected string $value;

	public function __construct(string $value) {
		$this->value = htmlspecialchars_decode($value);
		if (!count($matches = Safe::pregMatch("{itemref://(\d+)/(\d+)/(\d+)(?:&#39;|'|\x22)(?:>|&gt;)(.+?)(<|&lt;)/a(>|&gt;)}", $value))) {
			throw new InvalidArgumentException('Item is not matching the item spec');
		}
		$this->lowID = (int)$matches[1];
		$this->highID = (int)$matches[2];
		$this->ql = (int)$matches[3];
		$this->name = $matches[4];
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}

	public function getLowID(): int {
		return $this->lowID;
	}

	public function getHighID(): int {
		return $this->highID;
	}

	public function getLowQL(): int {
		return $this->ql;
	}

	public function getHighQL(): int {
		return $this->ql;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getLink(?int $ql=null, ?string $text=null): string {
		$ql ??= $this->getQL();
		$text ??= $this->getName();
		return "<a href='itemref://{$this->getLowID()}/{$this->getHighID()}/{$ql}'>{$text}</a>";
	}

	public function atQL(int $ql): static {
		$new = clone $this;
		$new->ql = $ql;
		return $new;
	}

	public function getQL(): int {
		return $this->ql;
	}

	public function setQL(int $ql): self {
		$this->ql = $ql;
		return $this;
	}
}
