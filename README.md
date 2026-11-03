# Chunkit

Chunked, resumable file uploads for Laravel 11 and 12.

Uploading a large file in one request breaks in predictable ways: PHP's `upload_max_filesize` and `post_max_size` reject it, a proxy or worker times out halfway, and a flaky connection means starting again from byte zero. Chunkit fixes that without any extra infrastructure. The browser sends the file in small pieces, the server decides where an interrupted upload should continue from (it never trusts the client's idea of progress), and the pieces are merged into the final file by a queued job that streams them, so even a multi-gigabyte file never sits in memory. It ships four JSON routes, one migration, one Artisan command and no JavaScript, and it runs on the queue, cache and disks your app already has.

## Install

```bash
composer require muhpuc40/chunkit
```

Working on the package locally next to your app? Use a Composer path repository instead:

```json
"repositories": [{ "type": "path", "url": "../laravel-chunkit" }]
```

```bash
composer require muhpuc40/chunkit:@dev
```

The service provider is discovered automatically. Publish and run the migration (it is never loaded for you, so you decide when it runs):

```bash
php artisan vendor:publish --tag=chunkit-migrations
php artisan migrate
```

Publish the config file if you want to change anything:

```bash
php artisan vendor:publish --tag=chunkit-config
```

The package creates one table, `chunked_uploads`. It never creates, publishes or touches `jobs`, `job_batches` or `failed_jobs`, and it does not need Horizon or Redis.

### What your app needs

- **A real queue.** The merge runs as a queued job on your default queue connection, so `php artisan queue:work` (or your usual worker) must be running. With `QUEUE_CONNECTION=sync` the merge would run inside the `/complete` request, which defeats the point; use `database` or better.
- **A queue `retry_after` above 600 seconds.** `MergeChunksJob` has a 600 second timeout. If your connection's `retry_after` (or SQS visibility timeout) is shorter, a second worker may pick the job up while the first is still merging. Chunkit's merge lock makes the second worker step aside, but raising `retry_after` is cleaner.
- **A cache store that supports locks:** `file`, `database`, `redis`, `memcached`, `dynamodb` or `array`. Redis is not required. The `database` store needs the `cache_locks` table that Laravel's default migrations create.
- **PHP limits above the chunk size.** Each chunk is an ordinary PHP file upload, so `upload_max_filesize` and `post_max_size` must be larger than `chunk_size` (5 MiB by default, so 8M or more is comfortable).
- **The `fileinfo` PHP extension**, used to check the real type of the merged file.
- **A CSRF token on the page.** The routes use the `web` middleware group, so CSRF protection is on. Put `<meta name="csrf-token" content="{{ csrf_token() }}">` in your layout and send it as an `X-CSRF-TOKEN` header with every `fetch()` and `XMLHttpRequest`, as the example below does.

## Config

Every option is documented inside `config/chunkit.php`. The defaults:

| Option | Default | What it does |
| --- | --- | --- |
| `chunk_size` | 5 MiB | Size of each chunk the browser is told to send. Set by the server and saved per upload, so changing it never breaks uploads in flight. The client cannot choose it. |
| `max_chunk_size` | 10 MiB | Hard cap. `chunk_size` must not exceed it. |
| `max_total_size` | 5 GiB | Largest complete file `/initiate` accepts. This is what protects your disk. |
| `allowed_mimes` | video, PDF, zip/rar, common images | Exact types or `family/*` wildcards, checked against the file's **real content** after the merge. An empty array rejects everything. |
| `blocked_extensions` | `php`, `phtml`, `phar`, `exe`, `sh`, ... | Always refused, even when the type is allowed. `shell.php.jpg` is refused too. |
| `ttl_hours` | 24 | How long an unfinished upload is kept after its last activity. |
| `chunk_disk` | `local` | Disk that holds chunks while an upload runs. Local-driver disks only. |
| `chunk_directory` | `chunkit` | Folder on that disk. Chunks live at `{chunk_directory}/{uploadId}/{index}`. |
| `final_disk` | `public` | Disk for the merged file. Local-driver disks only. |
| `final_path_pattern` | `uploads/{uuid}/{original_name}` | Path of the merged file. `{uuid}` is the upload id and `{original_name}` is the sanitised name. |
| `queue_connection` | `null` | Leave null to use the host app's default queue worker; only set this if you run a dedicated worker. |
| `queue_name` | `null` | Leave null to use the host app's default queue worker; only set this if you run a dedicated worker. |
| `route_prefix` | `chunkit` | URL prefix for every endpoint. |
| `middleware` | `['web']` | Middleware for every endpoint. |
| `lock_seconds` | 30 | How long a chunk lock may be held before it expires on its own. |
| `lock_wait_seconds` | 10 | How long a request waits for the lock before answering `409`. |

A few of these can also be set from `.env`: `CHUNKIT_CHUNK_SIZE`, `CHUNKIT_MAX_CHUNK_SIZE`, `CHUNKIT_MAX_TOTAL_SIZE`, `CHUNKIT_TTL_HOURS`, `CHUNKIT_CHUNK_DISK`, `CHUNKIT_FINAL_DISK`, `CHUNKIT_ROUTE_PREFIX`, `CHUNKIT_LOCK_SECONDS` and `CHUNKIT_LOCK_WAIT_SECONDS`.

### Who may upload

Uploads are not tied to a user, and anyone who can reach the routes can start one. Restrict them with middleware:

```php
'middleware' => ['web', 'auth'],
```

If you need to know who uploaded what, save the `upload_id` against your own record when the upload completes (see [Using the finished file](#using-the-finished-file)).

## API contract

All routes sit under the configured prefix (`/chunkit` by default). Every request must carry the CSRF token (`X-CSRF-TOKEN`), and `Accept: application/json` is recommended. Errors are always JSON.

| Method | Path | Request | Success response | Errors |
| --- | --- | --- | --- | --- |
| `POST` | `/initiate` | JSON: `original_name`, `total_bytes`, `mime_type` (optional) | `201` `{ upload_id, chunk_size, total_chunks }` | `422` invalid name, size or type |
| `POST` | `/{uploadId}/chunk` | multipart: `index`, `chunk`. Header: `Upload-Offset` | `200` `{ upload_id, offset, status, received_count, total_chunks }` and an `Upload-Offset` header | `404` unknown upload. `409` wrong offset, busy, or no longer accepting chunks. `410` expired. `422` bad index, missing file or wrong chunk size |
| `GET` | `/{uploadId}/status` | none | `200` `{ upload_id, received_chunks, offset, status, chunk_size, total_chunks, total_bytes, failure_reason }` and an `Upload-Offset` header | `404` unknown upload |
| `POST` | `/{uploadId}/complete` | none | `202` `{ upload_id, status }` once the merge is queued (`200` if it already finished) | `404` unknown upload. `410` expired. `422` chunks still missing |

**Errors** are always `{ "message": "...", "errors": { "field": ["..."] } }`; `errors` appears only when there are field errors, and a `409` adds an `offset` key.

**The offset.** `Upload-Offset` on a request is the start byte of the chunk being sent, which is `index * chunk_size`. If it does not match, the server answers `409` and puts its real resume point in the `Upload-Offset` response header. That is the first gap in what it has received (`contiguous chunks * chunk_size`), not the offset of the rejected chunk. Chunks may arrive out of order; the offset only moves forward once the gap before it is filled. Sending a chunk the server already has is harmless and changes nothing.

**Chunk size.** Every chunk must be exactly `chunk_size` bytes except the last, which carries the remainder. Anything else is a `422`.

**Status.** An upload moves `pending` -> `uploading` -> `merging` -> `completed`, or to `failed`. When it fails, `failure_reason` says why in words that are safe to show a user (for example `The content of the file is not an allowed type.`). Details such as exception messages stay in your application log.

**What happens at `/complete`.** The server checks that every chunk is on disk, marks the upload `merging` and queues the job; it never merges inside the request. The job streams the chunks into the final file, reads the real file type from its first bytes, and only then marks the upload `completed`. If the content is not an allowed type, the merged file and the chunks are deleted, the upload is marked `failed` with a reason, and the job does not retry, because retrying cannot change the answer.

## Alpine.js example

### How resumability actually works

The server is the source of truth, and the client never remembers progress on its own. Whenever the client is unsure where it is, it asks: either by calling `GET /status` (after a page reload, or before retrying after a network error) or by reading the `Upload-Offset` header of a `409`. Both return the same number, the first byte the server is still missing. The client slices the file from there and carries on. Nothing is restarted, and nothing the server already holds is sent twice.

### The example

This is a complete, copy-pasteable Blade snippet. It assumes Alpine is already running on the page and `<meta name="csrf-token" content="{{ csrf_token() }}">` is in your layout's `<head>`. State lives in Alpine (`uploadId`, `file`, `totalChunks`, `offset`, `percent`, `status`). `fetch()` handles `/initiate`, `/status` and `/complete`, and `XMLHttpRequest` sends the chunks because only XHR exposes upload progress.

```blade
<div x-data="chunkitUploader()">
    <input type="file" x-on:change="pick($event)" :disabled="busy">

    <button type="button" x-on:click="start()" :disabled="!file || busy"
            x-text="status === 'error' ? 'Retry / resume' : 'Upload'"></button>

    <progress max="100" :value="percent" x-show="status !== 'idle'"></progress>
    <span x-show="status !== 'idle'" x-text="percent + '%'"></span>
    <p x-text="message"></p>

    {{-- Submit this with your own form once the upload is done. --}}
    <input type="hidden" name="chunkit_upload_id" :value="status === 'done' ? uploadId : ''">
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('chunkitUploader', (baseUrl = '/chunkit') => ({
            uploadId: null,
            file: null,
            totalChunks: 0,
            chunkSize: 0,
            offset: 0,
            percent: 0,
            status: 'idle', // 'idle' | 'uploading' | 'resuming' | 'done' | 'error'
            message: '',

            get busy() {
                return this.status === 'uploading' || this.status === 'resuming';
            },

            csrf() {
                return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            },

            storageKey() {
                return `chunkit:${this.file.name}:${this.file.size}:${this.file.lastModified}`;
            },

            pick(event) {
                this.file = event.target.files[0] ?? null;
                this.uploadId = null;
                this.offset = 0;
                this.percent = 0;
                this.status = 'idle';
                this.message = '';
            },

            // fetch() for /initiate, /status and /complete.
            async api(path, options = {}) {
                const response = await fetch(baseUrl + path, {
                    ...options,
                    headers: {
                        'X-CSRF-TOKEN': this.csrf(),
                        'Accept': 'application/json',
                        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
                    },
                });

                let body = {};
                try {
                    body = await response.json();
                } catch (error) {
                    body = { message: `Unexpected response (HTTP ${response.status}).` };
                }

                return { ok: response.ok, status: response.status, body };
            },

            async start() {
                if (!this.file) {
                    return;
                }

                this.message = '';

                try {
                    // Same file as an unfinished upload? Ask the server where it stands.
                    if (!(await this.restoreFromServer())) {
                        await this.initiate();
                    }

                    await this.sendRemainingChunks();
                    await this.complete();
                } catch (error) {
                    this.status = 'error';
                    this.message = error.message;
                }
            },

            useUpload(uploadId, info) {
                this.uploadId = uploadId;
                this.chunkSize = info.chunk_size;
                this.totalChunks = info.total_chunks;
                this.offset = info.offset;
                this.percent = Math.floor((this.offset / this.file.size) * 100);
            },

            async restoreFromServer() {
                const saved = localStorage.getItem(this.storageKey());

                if (!saved) {
                    return false;
                }

                this.status = 'resuming';
                this.message = 'Checking where the server left off...';

                // "Where am I?" The server answers with its real resume point.
                const { ok, body } = await this.api(`/${saved}/status`);

                if (!ok || !['pending', 'uploading', 'merging'].includes(body.status) || body.total_bytes !== this.file.size) {
                    localStorage.removeItem(this.storageKey());
                    return false;
                }

                this.useUpload(saved, body);
                return true;
            },

            async initiate() {
                this.status = 'uploading';

                const { ok, status, body } = await this.api('/initiate', {
                    method: 'POST',
                    body: JSON.stringify({
                        original_name: this.file.name,
                        total_bytes: this.file.size,
                        mime_type: this.file.type,
                    }),
                });

                if (!ok) {
                    throw new Error(body.message ?? `Could not start the upload (HTTP ${status}).`);
                }

                this.useUpload(body.upload_id, { ...body, offset: 0 });
                localStorage.setItem(this.storageKey(), body.upload_id);
            },

            async sendRemainingChunks() {
                this.status = 'uploading';
                this.message = '';
                let conflicts = 0;

                while (this.offset < this.file.size) {
                    const index = Math.floor(this.offset / this.chunkSize);
                    const response = await this.sendChunk(index);

                    if (response.status === 200) {
                        conflicts = 0;
                        this.offset = response.offset;
                        this.percent = Math.floor((this.offset / this.file.size) * 100);
                    } else if (response.status === 409) {
                        // The server disagrees with where we think we are. The
                        // Upload-Offset header of the 409 is its real resume point
                        // (the first gap), so continue from THERE instead of restarting.
                        if (++conflicts > 5 || Number.isNaN(response.offset)) {
                            throw new Error(response.message ?? 'The server keeps rejecting this chunk.');
                        }

                        this.status = 'resuming';
                        this.message = `Resuming from byte ${response.offset}...`;
                        this.offset = response.offset;
                        this.status = 'uploading';
                    } else {
                        throw new Error(response.message ?? `Chunk ${index} failed (HTTP ${response.status}).`);
                    }
                }
            },

            // XMLHttpRequest for the chunk POST, because only XHR has upload.onprogress.
            sendChunk(index) {
                return new Promise((resolve, reject) => {
                    const start = index * this.chunkSize;
                    const form = new FormData();
                    form.append('index', index);
                    form.append('chunk', this.file.slice(start, start + this.chunkSize), 'chunk.bin');

                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', `${baseUrl}/${this.uploadId}/chunk`);
                    xhr.setRequestHeader('X-CSRF-TOKEN', this.csrf());
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.setRequestHeader('Upload-Offset', String(start));

                    xhr.upload.onprogress = (event) => {
                        if (event.lengthComputable) {
                            this.percent = Math.min(99, Math.floor(((start + event.loaded) / this.file.size) * 100));
                        }
                    };

                    xhr.onload = () => {
                        let message;
                        try {
                            message = JSON.parse(xhr.responseText).message;
                        } catch (error) {
                            message = undefined;
                        }

                        resolve({
                            status: xhr.status,
                            offset: parseInt(xhr.getResponseHeader('Upload-Offset'), 10),
                            message,
                        });
                    };

                    xhr.onerror = () => reject(new Error('Network error. Press the button again to resume.'));

                    xhr.send(form);
                });
            },

            async complete() {
                const finish = await this.api(`/${this.uploadId}/complete`, { method: 'POST' });

                if (!finish.ok) {
                    throw new Error(finish.body.message ?? `Could not finish the upload (HTTP ${finish.status}).`);
                }

                // 202: the merge is queued. Poll /status until the worker is done.
                this.message = 'Processing the file...';

                for (let attempt = 0; attempt < 400; attempt++) {
                    const { body } = await this.api(`/${this.uploadId}/status`);

                    if (body.status === 'completed') {
                        localStorage.removeItem(this.storageKey());
                        this.percent = 100;
                        this.status = 'done';
                        this.message = 'Upload complete.';
                        return;
                    }

                    if (body.status === 'failed') {
                        localStorage.removeItem(this.storageKey());
                        this.uploadId = null;
                        throw new Error(body.failure_reason ?? 'The file was rejected.');
                    }

                    await new Promise((resolve) => setTimeout(resolve, 1500));
                }

                throw new Error('The server is taking too long to process the file. Try again in a moment.');
            },
        }));
    });
</script>
```

### Using the finished file

The browser only ever sees the `upload_id`. Do not accept a file path from it. Look the upload up on the server and read the path from the row:

```php
use Illuminate\Support\Facades\Storage;
use Muhpuc40\Chunkit\Models\ChunkedUpload;

$upload = ChunkedUpload::query()
    ->where('upload_id', $request->string('chunkit_upload_id'))
    ->where('status', ChunkedUpload::STATUS_COMPLETED)
    ->firstOrFail();

// e.g. "uploads/6f1c.../lecture.pdf" on the disk set in chunkit.final_disk
$path = $upload->destination_path;

Storage::disk(config('chunkit.final_disk'))->move($path, "courses/{$course->id}/".basename($path));
```

Chunkit does not record who started an upload, so check ownership in your own code if that matters to you.

## Scheduling

Chunkit never schedules anything by itself. Abandoned uploads (unfinished, and past `ttl_hours` since their last activity) are removed by `php artisan chunkit:prune`, which deletes the database row and the chunk directory. An upload whose merge is running is left alone, and completed uploads are never pruned. Add it to the schedule yourself.

Laravel 11 and 12, in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('chunkit:prune')->hourly();
```

Older style, in `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('chunkit:prune')->hourly();
}
```

Run `php artisan chunkit:prune --dry-run` to see what would be deleted without deleting it. The scheduler itself still needs its usual single cron entry (`php artisan schedule:run` every minute).

## Testing

The package tests itself with [Pest](https://pestphp.com) on [Orchestra Testbench](https://packages.tools/testbench), with no host app, no `.env` and no real database. Disks are local directories in a temporary folder that is deleted after each test, the database is in-memory SQLite, the cache is the array store and the queue is faked.

```bash
composer install
vendor/bin/pest
vendor/bin/pint --test
```

The suite covers the full upload and merge with a sha256 check, out-of-order and duplicate chunks, the `409` resume point, incomplete uploads, magic-byte rejection, streaming a 20 MiB file in constant memory, the job's failure path and queue defaults, pruning, route registration and publishing. GitHub Actions runs it on PHP 8.2, 8.3 and 8.4 against Laravel 11 and 12.

## License

MIT. See [LICENSE](LICENSE).
