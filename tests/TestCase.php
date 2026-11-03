<?php

declare(strict_types=1);

namespace Muhpuc40\Chunkit\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Muhpuc40\Chunkit\ChunkitServiceProvider;
use Muhpuc40\Chunkit\Jobs\MergeChunksJob;
use Muhpuc40\Chunkit\Models\ChunkedUpload;
use Muhpuc40\Chunkit\Services\ChunkManager;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots a throwaway Laravel application with Orchestra Testbench, so the
 * package is tested on its own: no host app, no host .env, no host database.
 *
 *  - Both disks are local-driver disks rooted in a unique temp directory that
 *    is deleted after every test, so the real storage/app is never touched.
 *  - The database is in-memory SQLite and the package migration is run
 *    directly (the migration is publish-only, so nothing auto-loads it).
 *  - The cache is the array store (it supports atomic locks, like production).
 *  - The queue is faked, so a test decides when the merge job runs.
 *
 * Works unchanged under Testbench 9 (Laravel 11) and Testbench 10 (Laravel 12).
 */
abstract class TestCase extends Orchestra
{
    /**
     * Chunk size used by the tests: small enough that a few hundred bytes
     * make several chunks. Tests that need real sizes override it.
     */
    public const CHUNK_SIZE = 1024;

    protected string $diskRoot = '';

    protected function setUp(): void
    {
        // Needed before parent::setUp(): defineEnvironment() runs inside it.
        $this->diskRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'chunkit-tests-'.bin2hex(random_bytes(6));

        parent::setUp();

        $this->runPackageMigration();

        Queue::fake();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem)->deleteDirectory($this->diskRoot);
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ChunkitServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $config->set('cache.default', 'array');
        $config->set('queue.default', 'sync');

        $config->set('filesystems.default', 'local');
        $config->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => $this->diskRoot.DIRECTORY_SEPARATOR.'local',
            'throw' => false,
        ]);
        $config->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => $this->diskRoot.DIRECTORY_SEPARATOR.'public',
            'url' => '/storage',
            'visibility' => 'public',
            'throw' => false,
        ]);

        // The package merges its defaults underneath whatever is already set.
        $config->set('chunkit.chunk_size', self::CHUNK_SIZE);
    }

    private function runPackageMigration(): void
    {
        $migration = require __DIR__.'/../database/migrations/create_chunked_uploads_table.php';

        $migration->up();
    }

    /**
     * Content that the real file-type check recognises as a PDF.
     */
    protected function pdfContent(int $bytes): string
    {
        return "%PDF-1.4\n".random_bytes(max(0, $bytes - 9));
    }

    /**
     * POST /chunkit/initiate and return the decoded body.
     *
     * @return array{upload_id: string, chunk_size: int, total_chunks: int}
     */
    protected function initiateUpload(int $totalBytes, string $name = 'lecture.pdf', ?string $mime = 'application/pdf'): array
    {
        $payload = ['original_name' => $name, 'total_bytes' => $totalBytes];

        if ($mime !== null) {
            $payload['mime_type'] = $mime;
        }

        $response = $this->postJson('/chunkit/initiate', $payload);
        $response->assertCreated();

        /** @var array{upload_id: string, chunk_size: int, total_chunks: int} */
        return $response->json();
    }

    /**
     * Send one chunk of $content the way the browser does: a multipart body with
     * index + chunk and the Upload-Offset header (the chunk's start byte unless
     * a different $offset is given on purpose).
     */
    protected function sendChunk(string $uploadId, string $content, int $chunkSize, int $index, ?int $offset = null): TestResponse
    {
        $slice = substr($content, $index * $chunkSize, $chunkSize);

        return $this->post(
            "/chunkit/{$uploadId}/chunk",
            [
                'index' => $index,
                'chunk' => UploadedFile::fake()->createWithContent("chunk-{$index}.bin", $slice),
            ],
            [
                'Accept' => 'application/json',
                'Upload-Offset' => (string) ($offset ?? $index * $chunkSize),
            ],
        );
    }

    /**
     * Send every chunk of $content, in the given order (default: 0..n-1).
     *
     * @param  array<int, int>|null  $order
     */
    protected function uploadAllChunks(string $uploadId, string $content, int $chunkSize, ?array $order = null): void
    {
        $order ??= range(0, (int) ceil(strlen($content) / $chunkSize) - 1);

        foreach ($order as $index) {
            $this->sendChunk($uploadId, $content, $chunkSize, $index)->assertOk();
        }
    }

    protected function completeUpload(string $uploadId): TestResponse
    {
        return $this->postJson("/chunkit/{$uploadId}/complete");
    }

    /**
     * Run the queued MergeChunksJob the way a worker would.
     */
    protected function runQueuedMerge(string $uploadId): void
    {
        $job = Queue::pushed(
            MergeChunksJob::class,
            fn (MergeChunksJob $job): bool => $job->uploadId === $uploadId,
        )->last();

        $this->assertNotNull($job, 'No MergeChunksJob was queued for this upload.');

        $job->handle($this->app->make(ChunkManager::class));
    }

    /**
     * Start an upload and put its first chunk on disk. Returns the upload id.
     */
    protected function startUploadWithChunk(string $name = 'lecture.pdf'): string
    {
        $content = $this->pdfContent(2500);
        $id = $this->initiateUpload(2500, $name)['upload_id'];

        $this->sendChunk($id, $content, self::CHUNK_SIZE, 0)->assertOk();

        return $id;
    }

    protected function uploadRow(string $uploadId): ChunkedUpload
    {
        return ChunkedUpload::query()->where('upload_id', $uploadId)->firstOrFail();
    }

    protected function expireUpload(string $uploadId): void
    {
        ChunkedUpload::query()
            ->where('upload_id', $uploadId)
            ->update(['expires_at' => now()->subHour()]);
    }
}
