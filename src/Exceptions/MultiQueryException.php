<?php

namespace Mojahed\Exceptions;

use Exception;

class MultiQueryException extends Exception
{
    protected array   $results     = [];
    protected int     $failedIndex = -1;
    protected string  $queryError  = '';

    public static function fromFailedQuery(
        int    $index,
        string $error,
        array  $results = []
    ): self {
        $instance              = new self("MultiQuery failed on query index [{$index}]: {$error}");
        $instance->failedIndex = $index;
        $instance->queryError  = $error;
        $instance->results     = $results;
        return $instance;
    }

    public static function binaryNotFound(string $path): self
    {
        return new self("msquery binary not found at [{$path}]. Run: php artisan multiquery:install");
    }

    public static function executionFailed(string $error): self
    {
        return new self("msquery execution failed: {$error}");
    }

    public function getFailedIndex(): int
    {
        return $this->failedIndex;
    }

    public function getErrorString(): string
    {
        return $this->queryError;
    }

    public function getResults(): array
    {
        return $this->results;
    }
}
