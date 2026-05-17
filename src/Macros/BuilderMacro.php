<?php

namespace Mojahed\Macros;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Mojahed\MqResult;

class BuilderMacro
{
    /**
     * Aggregate modes that require SQL rewriting.
     */
    private const AGGREGATE_MODES = ['count', 'sum', 'avg', 'min', 'max'];

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

            // determine SQL based on mode
            $aggregateModes = ['count', 'sum', 'avg', 'min', 'max'];

            if (in_array($mode, $aggregateModes)) {
                // aggregate modes — rewrite SELECT using public API
                $clone = clone $this;
                $baseQuery = $clone instanceof EloquentBuilder ? $clone->toBase() : $clone;

                $col = ($mode === 'count') ? '*' : ($column ?? '*');
                $expression = strtoupper($mode) . '(' . $col . ') as aggregate';

                // use public methods only
                $baseQuery = $baseQuery->selectRaw($expression)->reorder()->limit(null)->offset(null);

                $sql      = $baseQuery->toSql();
                $bindings = $baseQuery->getBindings();

            } elseif ($mode === 'first' || $mode === 'value') {
                // first/value — add limit 1
                $clone = clone $this;
                $baseQuery = $clone instanceof EloquentBuilder ? $clone->toBase() : $clone;

                if ($mode === 'value' && $column) {
                    $baseQuery = $baseQuery->select($column);
                }

                $baseQuery = $baseQuery->limit(1);

                $sql      = $baseQuery->toSql();
                $bindings = $baseQuery->getBindings();

            } elseif ($mode === 'exists') {
                // exists — just limit 1, we check if any row returned
                $clone = clone $this;
                $baseQuery = $clone instanceof EloquentBuilder ? $clone->toBase() : $clone;
                $baseQuery = $baseQuery->select($baseQuery->raw(1))->limit(1);

                $sql      = $baseQuery->toSql();
                $bindings = $baseQuery->getBindings();

            } elseif ($mode === 'pluck' && $column) {
                // pluck — select only the target column
                $clone = clone $this;
                $baseQuery = $clone instanceof EloquentBuilder ? $clone->toBase() : $clone;
                $baseQuery = $baseQuery->select($column);

                $sql      = $baseQuery->toSql();
                $bindings = $baseQuery->getBindings();

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
