<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'attachable_type' => (new InboxItem)->getMorphClass(),
            'attachable_id' => InboxItem::factory(),
            'disk' => 'local',
            'path' => 'evidence/1/'.fake()->uuid().'.pdf',
            'original_filename' => 'certificate.pdf',
            'mime_type' => 'application/pdf',
            'size' => 120_000,
        ];
    }
}
