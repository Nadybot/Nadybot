<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Exception;
use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\GaugeProvider;
use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\ValueProvider;

use function Safe\json_encode;

class Dataset {
	/** @var string[] */
	private array $tags = [];

	/** @var ValueProvider[] */
	private array $providers = [];

	private string $name;

	public function __construct(string $name, string ...$tags) {
		sort($tags);
		$this->tags = $tags;
		$this->name = $name;
	}

	public function registerProvider(ValueProvider $provider): void {
		$tags = $provider->getTags();
		$tagKeys = array_keys($tags);
		sort($tagKeys);
		if ($tagKeys !== $this->tags) {
			throw new Exception("Incompatible tag-sets provided");
		}
		$this->providers []= $provider;
	}

	/** @return string[] */
	public function getValues(): array {
		if (empty($this->providers)) {
			return [];
		}
		$type = ($this->providers[0] instanceof GaugeProvider) ? "gauge" : "counter";
		$result = ["# TYPE {$this->name} {$type}"];
		foreach ($this->providers as $provider) {
			$line = $this->name;
			$tags = $provider->getTags();
			$attrs = join(
				",",
				array_map(
					function (string $tag) use ($tags): string {
						return "{$tag}=" . json_encode((string)$tags[$tag]);
					},
					$this->tags
				)
			);
			if (count($tags)) {
				$line .= "{" . $attrs . "}";
			}
			$line .= " " . (string)$provider->getValue();
			$result []= $line;
		}
		return $result;
	}
}
