<?php

namespace Illuminate\Database\Schema;

use Illuminate\Support\Fluent;

class ColumnDefinition extends Fluent {
	function after(string $column): self;
	function always(bool $value=true): self;
	function autoIncrement(): self;
	function change(): self;
	function charset(string $charset): self;
	function collation(string $collation): self;
	function comment(string $comment): self;
	function default(mixed $value): self;
	function first(): self;
	function from(int $startingValue): self;
	function generatedAs(string|\Illuminate\Database\Query\Expression $expression=null): self;
	function index(string $indexName=null): self;
	function invisible(): self;
	function nullable(bool $value=true): self;
	function persisted(): self;
	function primary(): self;
	function fulltext(string $indexName=null): self;
	function spatialIndex(string $indexName=null): self;
	function startingValue(int $startingValue): self;
	function storedAs(string $expression): self;
	function type(string $type): self;
	function unique(string $indexName=null): self;
	function unsigned(): self;
	function useCurrent(): self;
	function useCurrentOnUpdate(): self;
	function virtualAs(string $expression): self;
}
