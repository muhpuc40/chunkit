<?php

declare(strict_types=1);

namespace Muhpuc40\Chunkit\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OutOfRangeException;

/**
 * One row per upload. `received_chunks` is the source of truth for progress:
 * the byte offset is always derived from it, never taken from the client.
 *
 * @property int $id
 * @property string $upload_id
 * @property string $original_name
 * @property string|null $mime_type
 * @property int $total_chunks
 * @property int $chunk_size
 * @property int $total_bytes
 * @property array<int, int>|null $received_chunks
 * @property string $status
 * @property string|null $destination_path
 * @property string|null $failure_reason
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 *
 * @method static Builder<static> abandoned()
 */
class ChunkedUpload extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_MERGING = 'merging';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_UPLOADING,
        self::STATUS_MERGING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    protected $table = 'chunked_uploads';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'upload_id',
        'original_name',
        'mime_type',
        'total_chunks',
        'chunk_size',
        'total_bytes',
        'received_chunks',
        'status',
        'destination_path',
        'failure_reason',
        'expires_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'received_chunks' => '[]',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'total_chunks' => 'integer',
        'chunk_size' => 'integer',
        'total_bytes' => 'integer',
        'received_chunks' => 'array',
        'expires_at' => 'datetime',
    ];

    /**
     * Unfinished uploads whose expiry has passed: what chunkit:prune deletes.
     * Completed uploads are never abandoned, because their row records where
     * the finished file lives.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAbandoned(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', self::STATUS_COMPLETED)
            ->where('expires_at', '<', now());
    }

    /**
     * Received chunk indexes: unique, in range, ascending.
     *
     * @return list<int>
     */
    public function receivedIndexes(): array
    {
        $raw = $this->received_chunks;

        $indexes = array_map('intval', is_array($raw) ? $raw : []);

        $indexes = array_values(array_unique(array_filter(
            $indexes,
            fn (int $index): bool => $index >= 0 && $index < $this->total_chunks,
        )));

        sort($indexes);

        return $indexes;
    }

    public function hasChunk(int $index): bool
    {
        return in_array($index, $this->receivedIndexes(), true);
    }

    /**
     * How many chunks, counting from index 0, have arrived without a gap.
     */
    public function contiguousChunkCount(): int
    {
        $count = 0;

        foreach ($this->receivedIndexes() as $index) {
            if ($index !== $count) {
                break;
            }

            $count++;
        }

        return $count;
    }

    /**
     * The resume point in bytes: where the first gap in the received chunks
     * starts. This is what the server reports in the Upload-Offset header.
     */
    public function offset(): int
    {
        return min($this->contiguousChunkCount() * $this->chunk_size, $this->total_bytes);
    }

    /**
     * Where a given chunk starts, in bytes, within the final file.
     *
     * @throws OutOfRangeException When the index is not a valid chunk of this upload.
     */
    public function chunkOffset(int $index): int
    {
        $this->assertChunkIndex($index);

        return $index * $this->chunk_size;
    }

    /**
     * The exact size a chunk must have: every chunk is chunk_size bytes
     * except the last, which carries whatever remains.
     *
     * @throws OutOfRangeException When the index is not a valid chunk of this upload.
     */
    public function expectedChunkSize(int $index): int
    {
        $this->assertChunkIndex($index);

        if ($index === $this->total_chunks - 1) {
            return $this->total_bytes - ($index * $this->chunk_size);
        }

        return $this->chunk_size;
    }

    public function isComplete(): bool
    {
        return $this->total_chunks > 0
            && count($this->receivedIndexes()) === $this->total_chunks;
    }

    /**
     * Chunks are only accepted before the merge starts.
     */
    public function acceptsChunks(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_UPLOADING], true);
    }

    /**
     * @throws OutOfRangeException
     */
    private function assertChunkIndex(int $index): void
    {
        if ($index < 0 || $index >= $this->total_chunks) {
            throw new OutOfRangeException(
                "Chunk index {$index} is outside 0..".($this->total_chunks - 1).'.'
            );
        }
    }
}
