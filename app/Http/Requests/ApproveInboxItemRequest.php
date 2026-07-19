<?php

namespace App\Http\Requests;

use App\Models\InboxItem;
use Illuminate\Foundation\Http\FormRequest;

class ApproveInboxItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = $this->route('item');

        return $item instanceof InboxItem && $item->user_id === $this->user()->id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'activity_type_slug' => ['required', 'string'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'organisation' => ['nullable', 'string', 'max:255'],
            'cpd_points' => ['required', 'numeric', 'min:0', 'max:999'],
            'summary' => ['nullable', 'string', 'max:20000'],
            'reflection_draft' => ['nullable', 'array'],
            'reflection_draft.*' => ['nullable', 'string', 'max:20000'],
            'category_slugs' => ['nullable', 'array'],
            'category_slugs.*' => ['string'],
            'domain_codes' => ['nullable', 'array'],
            'domain_codes.*' => ['string'],
            'attribute_codes' => ['nullable', 'array'],
            'attribute_codes.*' => ['string'],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['integer'],
            'linked_activity_ids' => ['nullable', 'array'],
            'linked_activity_ids.*' => ['integer'],
            'keep_attachment_ids' => ['nullable', 'array'],
            'keep_attachment_ids.*' => ['integer'],
            'pii_ack' => ['nullable', 'boolean'],
        ];
    }
}
