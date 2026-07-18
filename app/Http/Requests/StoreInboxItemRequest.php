<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInboxItemRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255', 'required_without_all:files,url'],
            'details' => ['nullable', 'string', 'max:20000'],
            'url' => ['nullable', 'url', 'max:2048'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', 'max:25600', 'mimes:pdf,jpg,jpeg,png,webp,heic,gif,doc,docx,ppt,pptx,txt'],
        ];
    }
}
