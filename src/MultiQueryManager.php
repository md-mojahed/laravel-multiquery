<?php

namespace Mojahed;

use Illuminate\Support\Collection;
use Mojahed\Binary\BinaryExecutor;
use Mojahed\Exceptions\MultiQueryException;

class MultiQueryManager
{
    protected ?string $activeConnection = null;

    public function __construct(
        protected BinaryExecutor $executor,
        protected array          $config,
    ) {}

    // fluent connection setter
    public function connection(string $name): static
    {
        $clone                    = clone $this;
        $clone->activeConnection  = $name;
        return $clone;
    }

    // main entry point
    public function run(array $queries, array $map = []): array
    {
        // resolve all queries to MqResult
        $resolved = $this->resolveQueries($queries);

        // build binary args
        $args = $this->buildArgs($resolved);

        // call binary
        // process timeout is a safety net — generous enough to never
        // kill a healthy binary, but prevents PHP from hanging forever
        // if the binary deadlocks. Connection timeout + 60s buffer.
        $processTimeout = (int) $this->config['timeout'] + 60;

        $response = $this->executor->execute(
            $this->config['binary'],
            $args,
            $processTimeout,
        );

        // handle failures
        if (!$response['success'] && $this->config['throw']) {
            foreach ($response['results'] as $result) {
                if (!$result['success']) {
                    throw MultiQueryException::fromFailedQuery(
                        $result['index'],
                        $result['error'],
                        $response['results'],
                    );
                }
            }
        }

        // format and return results
        return $this->formatResults(
            $response['results'],
            $resolved,
            $map,
            array_keys($queries),
        );
    }

    // convert raw result to model Collection manually
    public function convert(array $rawResult, string $modelClass): Collection
    {
        return collect($rawResult)->fromMq($modelClass);
    }

    // resolve each query to MqResult
    protected function resolveQueries(array $queries): array
    {
        $resolved = [];

        foreach (array_values($queries) as $query) {
            if ($query instanceof MqResult) {
                $resolved[] = $query;
                continue;
            }

            // raw SQL string
            if (is_string($query)) {
                $resolved[] = new MqResult(sql: $query);
                continue;
            }

            // Query Builder or Eloquent Builder — call mq() automatically
            if (method_exists($query, 'mq')) {
                $resolved[] = $query->mq();
                continue;
            }

            throw new \InvalidArgumentException(
                'Each query must be a string, Builder instance, or MqResult.'
            );
        }

        return $resolved;
    }

    // build args for binary
    protected function buildArgs(array $resolved): array
    {
        // use first query's connection or active or config default
        $connectionName = $this->activeConnection
            ?? $resolved[0]->connection
            ?? $this->config['connection'];

        $dbConfig = config("database.connections.{$connectionName}");

        $args = [
            'host'    => [$dbConfig['host']],
            'port'    => [$dbConfig['port']],
            'user'    => [$dbConfig['username']],
            'pass'    => [$dbConfig['password']],
            'db'      => [$dbConfig['database']],
            'timeout' => [$this->config['timeout']],
            'query'   => [],
        ];

        foreach ($resolved as $mqResult) {
            $args['query'][] = $this->mergeSql($mqResult);
        }

        return $args;
    }

    // merge bindings into SQL
    protected function mergeSql(MqResult $mqResult): string
    {
        $sql      = $mqResult->sql;
        $bindings = $mqResult->bindings;

        // get PDO for proper quoting
        $connectionName = $this->activeConnection
            ?? $mqResult->connection
            ?? $this->config['connection'];
        $pdo = app('db')->connection($connectionName)->getPdo();

        foreach ($bindings as $binding) {
            $value = match (true) {
                is_null($binding)   => 'NULL',
                is_bool($binding)   => $binding ? '1' : '0',
                is_string($binding) => $pdo->quote($binding),
                default             => (string) $binding,
            };

            // use strpos + substr_replace to avoid preg_replace backreference issues
            $pos = strpos($sql, '?');
            if ($pos !== false) {
                $sql = substr_replace($sql, $value, $pos, 1);
            }
        }

        return $sql;
    }

    // format raw Go results according to mode and map
    protected function formatResults(
        array $rawResults,
        array $resolved,
        array $map,
        array $originalKeys,
    ): array {
        // sort by index to maintain order
        usort($rawResults, function ($a, $b) {
            return $a['index'] <=> $b['index'];
        });

        $formatted = [];

        foreach ($rawResults as $raw) {
            $index   = $raw['index'];
            $mqResult = $resolved[$index];
            $rows    = $raw['result'] ?? [];
            $key     = $originalKeys[$index];

            // determine model class — map overrides auto detected
            $modelClass = $map[$key] ?? $map[$index] ?? $mqResult->model ?? null;

            $value = $this->applyMode($rows, $mqResult->mode, $mqResult->column, $modelClass);

            // use original key (named or numeric)
            $formatted[$key] = $value;
        }

        return $formatted;
    }

    // apply mode to raw rows
    protected function applyMode(
        array   $rows,
        string  $mode,
        ?string $column,
        ?string $modelClass,
    ): mixed {
        return match ($mode) {
            'first'  => $this->applyFirst($rows, $modelClass),
            'count'  => (int) ($rows[0]['aggregate'] ?? 0),
            'sum'    => (float) ($rows[0]['aggregate'] ?? 0),
            'avg'    => (float) ($rows[0]['aggregate'] ?? 0),
            'min'    => $rows[0]['aggregate'] ?? null,
            'max'    => $rows[0]['aggregate'] ?? null,
            'pluck'  => array_column($rows, $column),
            'value'  => $rows[0][$column] ?? null,
            'exists' => !empty($rows),
            default  => $this->applyGet($rows, $modelClass),  // 'get'
        };
    }

    protected function applyGet(array $rows, ?string $modelClass): Collection
    {
        $collection = collect($rows);

        if ($modelClass) {
            return $collection->fromMq($modelClass);
        }

        return $collection;
    }

    protected function applyFirst(array $rows, ?string $modelClass): mixed
    {
        if (empty($rows)) {
            return null;
        }

        $row = $rows[0];

        if ($modelClass) {
            $instance = new $modelClass();
            $instance->exists = true;
            return $instance->forceFill((array) $row)->syncOriginal();
        }

        return (object) $row;
    }
}
