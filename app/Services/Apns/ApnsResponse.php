<?php

namespace App\Services\Apns;

final readonly class ApnsResponse
{
    public function __construct(
        public int $status,
        public ?string $reason = null,
    ) {}

    public function delivered(): bool
    {
        return $this->status === 200;
    }

    /**
     * APNs says this token will never work again: the app was deleted
     * (410 Unregistered) or the token belongs to another app entirely.
     */
    public function tokenGone(): bool
    {
        return $this->status === 410
            || in_array($this->reason, ['BadDeviceToken', 'DeviceTokenNotForTopic'], true);
    }
}
