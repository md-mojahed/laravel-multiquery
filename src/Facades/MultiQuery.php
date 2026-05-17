<?php

namespace Mojahed\Facades;

use Illuminate\Support\Facades\Facade;
use Mojahed\MultiQueryManager;

/**
 * @method static static connection(string $name)
 * @method static array run(array $queries, array $map = [])
 * @method static \Illuminate\Support\Collection convert(array $rawResult, string $modelClass)
 *
 * @see MultiQueryManager
 */
class MultiQuery extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MultiQueryManager::class;
    }
}
