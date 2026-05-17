<?php

namespace Mojahed\Macros;

use Illuminate\Support\Collection;

class CollectionMacro
{
    public static function register(): void
    {
        Collection::macro('fromMq', function (string $modelClass) {
            return $this->map(function ($item) use ($modelClass) {
                $instance = new $modelClass();
                $instance->exists = true;
                return $instance->forceFill((array) $item)->syncOriginal();
            });
        });
    }
}
