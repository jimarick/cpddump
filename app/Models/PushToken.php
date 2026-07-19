<?php

namespace App\Models;

use Database\Factories\PushTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A device's APNs token. One row per physical device token — re-registering
 * moves the token to whichever account is signed in on that device.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $platform
 * @property string $device_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PushToken extends Model
{
    /** @use HasFactory<PushTokenFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
