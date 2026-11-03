<?php

declare(strict_types=1);

namespace Muhpuc40\Chunkit\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * POST /{uploadId}/complete
 *
 * There is no body: whether the upload is really complete is decided by the
 * server from the chunks it holds, never by anything the client says.
 */
class CompleteUploadRequest extends FormRequest
{
    /**
     * Who may upload is decided by the middleware in config('chunkit.middleware').
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['upload_id' => $this->route('uploadId')]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'upload_id' => ['required', 'uuid'],
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
