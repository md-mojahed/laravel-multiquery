<?php

namespace Mojahed\Macros;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Mojahed\MqResult;

class BuilderMacro
{
    public static function register(): void
    {
        $macro = function (string $mode = 'get', ?string $column = null) {
            // detect model class if Eloquent builder
            $model = null;
            if (method_exists($this, 'getModel')) {
                $model = get_class($this->getModel());
            }

            // detect connection
            $connection = null;
            if (method_exists($this, 'getConnection')) {
                $connection = $this->getConnection()->getName();
            }

            return new MqResult(
                sql:        $this->toSql(),
                bindings:   $this->getBindings(),
                mode:       $mode,
                column:     $column,
                model:      $model,
                connection: $connection,
            );
        };

        // register on both builders
        EloquentBuilder::macro('mq', $macro);
        QueryBuilder::macro('mq', $macro);
    }
}
