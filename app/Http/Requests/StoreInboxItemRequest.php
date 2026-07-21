<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInboxItemRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255', 'required_without_all:files,url,notes'],
            'details' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:50000'],
            'occurred_on' => ['nullable', 'date'],
            'url' => ['nullable', 'url', 'max:2048'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', 'max:25600', 'mimes:'.implode(',', config('cpd.ingest.allowed_extensions'))],
        ];
    }
}
