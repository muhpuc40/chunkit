<?php

declare(strict_types=1);

namespace Muhpuc40\Chunkit\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Muhpuc40\Chunkit\Services\ChunkManager;

/**
 * POST /initiate
 *
 * Body: original_name, total_bytes, mime_type (optional, the browser's File.type).
 * The chunk size is deliberately not an input: the server decides it.
 */
class InitiateUploadRequest extends FormRequest
{
    /**
     * Who may upload is decided by the middleware in config('chunkit.middleware').
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maxTotalBytes = (int) config('chunkit.max_total_size');

        return [
            'original_name' => ['required', 'string', 'max:255'],
            'total_bytes' => ['required', 'integer', 'min:1', 'max:'.$maxTotalBytes],
            'mime_type' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && ! ChunkManager::mimeIsAllowed($value, (array) config('chunkit.allowed_mimes'))) {
                        $fail('Files of this type are not allowed.');
                    }
                },
            ],
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
