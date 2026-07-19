<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The only thing that survives binning a calendar-sourced item: its UID,
 * so the weekly sync never resurrects an event the user threw away.
 * No content is kept — deletion elsewhere is total.
 *
 * @property int $id
 * @property int $user_id
 * @property string $uid
 * @property Carbon $dismissed_at
 */
class DismissedCalendarEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
