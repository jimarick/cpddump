<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One request shape for both merge families (web modal and companion API).
 * Structural rules live here; ownership, status and period coherence are
 * checked in ActivityMerger, which owns the semantics.
 */
class MergeActivitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'activity_ids' => ['nullable', 'array'],
            'activity_ids.*' => ['integer'],
            'inbox_item_ids' => ['nullable', 'array'],
            'inbox_item_ids.*' => ['integer'],
            'into_activity_id' => ['nullable', 'integer'],

            'title' => ['required', 'string', 'max:255'],
            'activity_type_slug' => ['required', 'string'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'organisation' => ['nullable', 'string', 'max:255'],
            'cpd_points' => ['required', 'numeric', 'min:0', 'max:999'],
            'details' => ['nullable', 'string', 'max:20000'],
            'reflection' => ['nullable', 'array'],
            'reflection.*' => ['nullable', 'string', 'max:20000'],
            'category_slugs' => ['nullable', 'array'],
            'category_slugs.*' => ['string'],
            'domain_codes' => ['nullable', 'array'],
            'domain_codes.*' => ['string'],
            'attribute_codes' => ['nullable', 'array'],
            'attribute_codes.*' => ['string'],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['integer'],
            'keep_attachment_ids' => ['nullable', 'array'],
            'keep_attachment_ids.*' => ['integer'],
            'pii_acks' => ['nullable', 'array'],
            'pii_acks.*' => ['integer'],
        ];
    }
}
