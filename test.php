<?php declare(strict_types=1);

use Amp\Pipeline\Pipeline;

require 'vendor/autoload.php';

$result = Pipeline::fromIterable([1, 2, 3, 4])
  ->map(function (int $item): string {
    return (string)($item * $item);
  })->toArray();

var_dump($result);
