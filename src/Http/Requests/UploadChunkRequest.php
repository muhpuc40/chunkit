<?php

declare(strict_types=1);

namespace Muhpuc40\Chunkit\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Muhpuc40\Chunkit\Models\ChunkedUpload;

/**
 * POST /{uploadId}/chunk
 *
 * Body (multipart): index, chunk. Header: Upload-Offset, the start byte of
 * this chunk (index * chunk_size).
 *
 * A chunk's own MIME type is not checked here: a slice of a video sniffs as
 * application/octet-stream. The file's type is verified from its real bytes
 * once the whole file has been merged.
 *
 * The chunk's size is not limited here either: ChunkManager::storeChunk()
 * compares it with the exact size the upload expects, which is the single
 * authority on chunk size (and chunk_size can never exceed max_chunk_size).
 */
class UploadChunkRequest extends FormRequest
{
    /**
     * Who may upload is decided by the middleware in config('chunkit.middleware').
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Treat the Upload-Offset header as a validated input like any other.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['upload_offset' => $this->header('Upload-Offset')]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'index' => [
                'bail',
                'required',
                'integer',
                'min:0',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $totalChunks = ChunkedUpload::query()
                        ->where('upload_id', (string) $this->route('uploadId'))
                        ->value('total_chunks');

                    // An unknown upload is reported as 404 by the manager, not here.
                    if ($totalChunks !== null && (int) $value >= (int) $totalChunks) {
                        $fail('The chunk index must be between 0 and '.((int) $totalChunks - 1).'.');
                    }
                },
            ],
            'chunk' => ['required', 'file'],
            'upload_offset' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'upload_offset.required' => 'The Upload-Offset header is required.',
            'upload_offset.integer' => 'The Upload-Offset header must be a whole number of bytes.',
            'upload_offset.min' => 'The Upload-Offset header cannot be negative.',
        ];
    }

    /**
     * Always answer with JSON, even when the client forgot the Accept header,
     * instead of Laravel's redirect-back for browser requests.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first() ?: 'The given data was invalid.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
