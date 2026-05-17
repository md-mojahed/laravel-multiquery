<?php

namespace Mojahed;

class MqResult
{
    public function __construct(
        public readonly string  $sql,
        public readonly array   $bindings   = [],
        public readonly string  $mode       = 'get',
        public readonly ?string $column     = null,
        public readonly ?string $model      = null,
        public readonly ?string $connection = null,
    ) {}
}
