<?php

declare(strict_types=1);

namespace Muhpuc40\Chunkit\Contracts;

use InvalidArgumentException;
use RuntimeException;

/**
 * Where chunks live between the first request and the merge.
 *
 * v1 ships a local-disk implementation. Keeping the package against this
 * contract means an S3/R2 driver can be added later without touching the
 * ChunkManager, the controller or the public HTTP API.
 *
 * Implementations MUST stream: no method may load a whole chunk, and
 * especially not a whole assembled file, into memory.
 */
interface ChunkStorage
{
    /**
     * Store one chunk, overwriting any earlier copy of the same index.
     *
     * Callers must record the chunk as received only AFTER this method has
     * returned, so a half-written chunk is never treated as received.
     *
     * @param  resource  $stream  Readable stream positioned at the start of the chunk.
     * @return int Number of bytes now stored for this chunk.
     *
     * @throws InvalidArgumentException When the upload id, index or stream is invalid.
     * @throws RuntimeException When the chunk could not be written.
     */
    public function putChunk(string $uploadId, int $index, $stream): int;

    /**
     * Whether a chunk is currently stored.
     *
     * @throws InvalidArgumentException When the upload id or index is invalid.
     */
    public function hasChunk(string $uploadId, int $index): bool;

    /**
     * Size in bytes of a stored chunk.
     *
     * @throws InvalidArgumentException When the upload id or index is invalid.
     * @throws RuntimeException When the chunk does not exist.
     */
    public function chunkSize(string $uploadId, int $index): int;

    /**
     * Open a stored chunk for reading. The caller must fclose() the stream.
     *
     * @return resource
     *
     * @throws InvalidArgumentException When the upload id or index is invalid.
     * @throws RuntimeException When the chunk does not exist or cannot be opened.
     */
    public function readChunkStream(string $uploadId, int $index);

    /**
     * Indexes of every chunk currently stored for an upload, ascending.
     *
     * @return list<int>
     *
     * @throws InvalidArgumentException When the upload id is invalid.
     */
    public function listChunks(string $uploadId): array;

    /**
     * Stream chunks 0..($totalChunks - 1), in order, into one file on the
     * destination disk and return the number of bytes written.
     *
     * The destination only appears once it is complete (it is built under a
     * temporary name and moved into place), and calling this again for the
     * same upload replaces the destination, so a retried merge is safe.
     *
     * @return int Bytes written to the destination.
     *
     * @throws InvalidArgumentException When the upload id, chunk count or destination path is invalid.
     * @throws RuntimeException When a chunk is missing, a copy is short, or the destination disk is unsupported.
     */
    public function assemble(string $uploadId, int $totalChunks, string $disk, string $path): int;

    /**
     * Delete every chunk of an upload and its directory. Deleting an upload
     * that has no chunks is not an error.
     *
     * @throws InvalidArgumentException When the upload id is invalid.
     * @throws RuntimeException When the directory still exists afterwards.
     */
    public function deleteUpload(string $uploadId): void;
}
