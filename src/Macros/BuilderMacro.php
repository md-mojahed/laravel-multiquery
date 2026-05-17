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

            // get the underlying query builder (works for both Eloquent and Query builders)
            $baseQuery = $this instanceof EloquentBuilder ? $this->getQuery() : $this;

            // determine SQL based on mode
            $aggregateModes = ['count', 'sum', 'avg', 'min', 'max'];

            if (in_array($mode, $aggregateModes)) {
                // aggregate modes — clone and rewrite SELECT
                $cloned = clone $baseQuery;

                $col = ($mode === 'count') ? '*' : ($column ?? '*');
                $expression = strtoupper($mode) . '(' . $col . ') as aggregate';

                $cloned->columns = null;
                $cloned->orders = null;
                $cloned->limit = null;
                $cloned->offset = null;
                $cloned->selectRaw($expression);

                $sql      = $cloned->toSql();
                $bindings = $cloned->getBindings();

            } elseif ($mode === 'first') {
                // first — just limit 1, keep select as-is
                $cloned = clone $baseQuery;
                $cloned->limit = 1;

                $sql      = $cloned->toSql();
                $bindings = $cloned->getBindings();

            } elseif ($mode === 'value') {
                // value — select single column, limit 1
                $cloned = clone $baseQuery;
                $cloned->limit = 1;
                if ($column) {
                    $cloned->columns = null;
                    $cloned->select($column);
                }

                $sql      = $cloned->toSql();
                $bindings = $cloned->getBindings();

            } elseif ($mode === 'exists') {
                // exists — select 1, limit 1
                $cloned = clone $baseQuery;
                $cloned->columns = null;
                $cloned->orders = null;
                $cloned->limit = 1;
                $cloned->selectRaw('1 as `exists`');

                $sql      = $cloned->toSql();
                $bindings = $cloned->getBindings();

            } elseif ($mode === 'pluck' && $column) {
                // pluck — select only the target column
                $cloned = clone $baseQuery;
                $cloned->columns = null;
                $cloned->select($column);

                $sql      = $cloned->toSql();
                $bindings = $cloned->getBindings();

            } else {
                // get (default) — use query as-is
                $sql      = $this->toSql();
                $bindings = $this->getBindings();
            }

            return new MqResult(
                sql:        $sql,
                bindings:   $bindings,
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
