<?php

namespace App\Models;

use App\Enums\CalendarFeedStatus;
use Database\Factories\CalendarFeedFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $label
 * @property string $url
 * @property string|null $provider_hint
 * @property CalendarFeedStatus $status
 * @property string|null $last_sync_error
 * @property Carbon|null $last_synced_at
 */
class CalendarFeed extends Model
{
    /** @use HasFactory<CalendarFeedFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'status' => CalendarFeedStatus::class,
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
