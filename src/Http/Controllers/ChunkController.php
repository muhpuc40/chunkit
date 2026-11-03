<?php

declare(strict_types=1);

namespace Muhpuc40\Chunkit\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Muhpuc40\Chunkit\Http\Requests\CompleteUploadRequest;
use Muhpuc40\Chunkit\Http\Requests\InitiateUploadRequest;
use Muhpuc40\Chunkit\Http\Requests\UploadChunkRequest;
use Muhpuc40\Chunkit\Models\ChunkedUpload;
use Muhpuc40\Chunkit\Services\ChunkManager;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Every failure is answered as { "message": "...", "errors": { ... } }
 * ("errors" only when there are field errors). A 409 also carries the
 * server's resume point as an "offset" key, mirroring the Upload-Offset header.
 *
 * Unexpected exceptions are not caught here: Laravel logs them and answers 500.
 */
class ChunkController extends Controller
{
    public function __construct(private readonly ChunkManager $manager) {}

    /**
     * POST /initiate: 201 { upload_id, chunk_size, total_chunks }
     */
    public function initiate(InitiateUploadRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $upload = $this->manager->initiate(
                (string) $request->validated('original_name'),
                (int) $request->validated('total_bytes'),
                $request->validated('mime_type'),
            );

            return new JsonResponse([
                'upload_id' => $upload->upload_id,
                'chunk_size' => $upload->chunk_size,
                'total_chunks' => $upload->total_chunks,
            ], 201);
        });
    }

    /**
     * POST /{uploadId}/chunk: 200 { upload_id, offset, status, received_count, total_chunks }
     *
     * The Upload-Offset REQUEST header is the start byte of the chunk being sent,
     * which is index * chunk_size.
     *
     * When it does not match, the answer is 409 and the Upload-Offset RESPONSE
     * header is the server's real resume point: contiguousChunkCount() * chunk_size,
     * the first gap in what the server has received. It is NOT the expected offset
     * of the rejected chunk. The client continues from that byte, which is
     * chunk number (offset / chunk_size).
     *
     * A successful response carries the same resume point in its Upload-Offset
     * header, so chunks sent out of order are fine: the offset only advances
     * once the gap before it is filled.
     */
    public function chunk(UploadChunkRequest $request, string $uploadId): JsonResponse
    {
        return $this->guard(function () use ($request, $uploadId): JsonResponse {
            /** @var UploadedFile $file */
            $file = $request->file('chunk');

            $handle = fopen($file->getRealPath(), 'rb');

            if ($handle === false) {
                throw new RuntimeException('Chunkit: could not read the uploaded chunk.');
            }

            try {
                $upload = $this->manager->storeChunk(
                    $uploadId,
                    (int) $request->validated('index'),
                    (int) $request->validated('upload_offset'),
                    $handle,
                );
            } finally {
                fclose($handle);
            }

            return new JsonResponse([
                'upload_id' => $upload->upload_id,
                'offset' => $upload->offset(),
                'status' => $upload->status,
                'received_count' => count($upload->receivedIndexes()),
                'total_chunks' => $upload->total_chunks,
            ], 200, ['Upload-Offset' => (string) $upload->offset()]);
        });
    }

    /**
     * GET /{uploadId}/status: 200 { received_chunks, offset, status, failure_reason, ... }
     *
     * This is how a client that lost its place asks "where am I?". The offset
     * is the server's resume point, and chunk_size / total_chunks are included
     * so a page that reloaded can carry on without having called /initiate.
     * failure_reason is null unless the upload failed, and is safe to show to the user.
     */
    public function status(string $uploadId): JsonResponse
    {
        return $this->guard(function () use ($uploadId): JsonResponse {
            $upload = $this->manager->status($uploadId);

            return new JsonResponse([
                'upload_id' => $upload->upload_id,
                'received_chunks' => $upload->receivedIndexes(),
                'offset' => $upload->offset(),
                'status' => $upload->status,
                'chunk_size' => $upload->chunk_size,
                'total_chunks' => $upload->total_chunks,
                'total_bytes' => $upload->total_bytes,
                'failure_reason' => $upload->failure_reason,
            ], 200, ['Upload-Offset' => (string) $upload->offset()]);
        });
    }

    /**
     * POST /{uploadId}/complete: 202 { upload_id, status } once the merge is
     * queued (or already running), 200 if it had already finished.
     */
    public function complete(CompleteUploadRequest $request, string $uploadId): JsonResponse
    {
        return $this->guard(function () use ($uploadId): JsonResponse {
            $upload = $this->manager->complete($uploadId);

            return new JsonResponse([
                'upload_id' => $upload->upload_id,
                'status' => $upload->status,
            ], $upload->status === ChunkedUpload::STATUS_COMPLETED ? 200 : 202);
        });
    }

    /**
     * Run an action and turn the manager's HTTP-meaningful exceptions into the
     * package's one JSON error shape.
     *
     * @param  Closure(): JsonResponse  $action
     */
    private function guard(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            return new JsonResponse([
                'message' => $e->validator->errors()->first() ?: 'The given data was invalid.',
                'errors' => $e->errors(),
            ], 422);
        } catch (HttpExceptionInterface $e) {
            $headers = $e->getHeaders();
            $body = ['message' => $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.'];

            if (isset($headers['Upload-Offset'])) {
                $body['offset'] = (int) $headers['Upload-Offset'];
            }

            return new JsonResponse($body, $e->getStatusCode(), $headers);
        }
    }
}
