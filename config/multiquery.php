<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Go Binary Path
    |--------------------------------------------------------------------------
    | Full path to the msquery binary on your server.
    */
    'binary' => env('MULTIQUERY_BIN', '/usr/local/bin/msquery'),

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    | Uses your Laravel database connection name from config/database.php
    */
    'connection' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Default Timeout
    |--------------------------------------------------------------------------
    | Per query timeout in seconds. Individual queries can override this.
    */
    'timeout' => env('MULTIQUERY_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Throw On Failure
    |--------------------------------------------------------------------------
    | If true, throws MultiQueryException when any query fails.
    | If false, failed queries return null in results array.
    */
    'throw' => true,

];
