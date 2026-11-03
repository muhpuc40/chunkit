<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Chunk size
    |--------------------------------------------------------------------------
    |
    | Size in bytes of each chunk the browser is told to send (default 5 MiB).
    | It is returned by POST /initiate and saved on the upload row, so changing
    | this later never breaks uploads that are already in flight.
    |
    | Every chunk is one normal PHP file upload, so this MUST stay below both
    | upload_max_filesize and post_max_size in php.ini, or PHP rejects the
    | request before Laravel sees it.
    |
    */

    'chunk_size' => (int) env('CHUNKIT_CHUNK_SIZE', 5 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Max chunk size
    |--------------------------------------------------------------------------
    |
    | Hard cap in bytes for a single chunk request (default 10 MiB). A chunk
    | larger than this is rejected with 422, whatever the client claims.
    |
    */

    'max_chunk_size' => (int) env('CHUNKIT_MAX_CHUNK_SIZE', 10 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Max total size
    |--------------------------------------------------------------------------
    |
    | Largest complete file in bytes that /initiate will accept (default 5 GiB).
    | This is what protects the disk, so set it to what you actually need.
    |
    */

    'max_total_size' => (int) env('CHUNKIT_MAX_TOTAL_SIZE', 5 * 1024 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME types
    |--------------------------------------------------------------------------
    |
    | Entries are either exact types ('video/mp4') or a family wildcard
    | ('video/*'). The type is checked when the upload is initiated and again
    | against the real file content before the merged file is published, so a
    | client cannot get past it by lying about the type.
    |
    | An empty array rejects every upload, so nothing is accepted by accident.
    |
    */

    'allowed_mimes' => [
        // Video
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'video/x-matroska',

        // Documents
        'application/pdf',

        // Archives
        'application/zip',
        'application/x-zip-compressed',
        'application/vnd.rar',
        'application/x-rar-compressed',
        'application/x-rar',

        // Images
        'image/jpeg',
        'image/png',
        'image/webp',
    ],

    /*
    |--------------------------------------------------------------------------
    | Blocked extensions
    |--------------------------------------------------------------------------
    |
    | File extensions (without the dot, any case) that are always refused, even
    | when the MIME type is allowed. Finished files are usually written to a web
    | accessible disk, so an uploaded script must never keep an executable name.
    |
    */

    'blocked_extensions' => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
        'pl', 'py', 'cgi', 'asp', 'aspx', 'jsp',
        'sh', 'bash', 'bat', 'cmd', 'ps1', 'exe', 'dll', 'com', 'msi',
        'htaccess', 'htpasswd',
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload lifetime (hours)
    |--------------------------------------------------------------------------
    |
    | How long an unfinished upload is kept after its last activity before
    | `php artisan chunkit:prune` may delete its row and its chunks.
    |
    */

    'ttl_hours' => (int) env('CHUNKIT_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Chunk storage
    |--------------------------------------------------------------------------
    |
    | chunk_disk is the disk that holds chunks while an upload is running and
    | chunk_directory is the folder on that disk. v1 supports local-driver
    | disks only. With Laravel 11+'s default "local" disk the chunks end up in
    | storage/app/private/{chunk_directory}/{uploadId}/{index}.
    |
    */

    'chunk_disk' => env('CHUNKIT_CHUNK_DISK', 'local'),

    'chunk_directory' => 'chunkit',

    /*
    |--------------------------------------------------------------------------
    | Final storage
    |--------------------------------------------------------------------------
    |
    | final_disk is where the merged file is written (v1: local-driver disks
    | only) and final_path_pattern is its path on that disk. Supported
    | placeholders: {uuid} (the upload id) and {original_name} (sanitised).
    | The default puts a finished file at storage/app/public/uploads/{uuid}/{name}.
    |
    */

    'final_disk' => env('CHUNKIT_FINAL_DISK', 'public'),

    'final_path_pattern' => 'uploads/{uuid}/{original_name}',

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The merge runs as a queued job. Leave queue_connection and queue_name
    | null to use the host app's default queue worker; only set these if you
    | run a dedicated worker.
    |
    */

    'queue_connection' => null,

    'queue_name' => null,

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | route_prefix is the URL prefix for every endpoint (default /chunkit).
    | middleware is applied to all of them. The default ['web'] keeps sessions
    | and CSRF protection, which Blade pages need. Add 'auth' here (for example
    | ['web', 'auth']) to restrict who may upload.
    |
    */

    'route_prefix' => env('CHUNKIT_ROUTE_PREFIX', 'chunkit'),

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Chunk locking
    |--------------------------------------------------------------------------
    |
    | Every chunk write runs inside Cache::lock("chunkit:{uploadId}").
    | lock_seconds is how long a lock may be held before it expires on its own
    | (a safety net for crashed requests). lock_wait_seconds is how long a
    | request waits for the lock before it answers 409.
    |
    | Locks need a cache store that supports them: file, database, redis,
    | memcached, dynamodb and array do. Redis is NOT required.
    |
    */

    'lock_seconds' => (int) env('CHUNKIT_LOCK_SECONDS', 30),

    'lock_wait_seconds' => (int) env('CHUNKIT_LOCK_WAIT_SECONDS', 10),

];
