<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

class ShoppingList {
	/**
	 * @param list<string> $implants
	 * @param list<string> $shinyClusters
	 * @param list<string> $brightClusters
	 * @param list<string> $fadedClusters
	 */
	public function __construct(
		public array $implants=[],
		public array $shinyClusters=[],
		public array $brightClusters=[],
		public array $fadedClusters=[],
	) {
	}

	/** Add a cluster in a specific grade to the shopping list */
	public function addCluster(ClusterGrade $grade, string $cluster): void {
		match ($grade) {
			ClusterGrade::Shiny => $this->shinyClusters []= $cluster,
			ClusterGrade::Bright => $this->brightClusters []= $cluster,
			ClusterGrade::Faded => $this->fadedClusters []= $cluster,
		};
	}
}
