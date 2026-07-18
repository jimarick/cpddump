<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AiProvider;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Paddle\Billable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $profession_id
 * @property bool $is_admin
 * @property string $timezone
 * @property string|null $inbound_email_token
 * @property AiProvider|null $ai_provider
 * @property string|null $ai_api_key
 * @property bool $weekly_email_enabled
 * @property Carbon|null $onboarded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'profession_id', 'timezone', 'ai_provider', 'ai_api_key', 'weekly_email_enabled', 'onboarded_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'ai_api_key'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
            'ai_provider' => AiProvider::class,
            'ai_api_key' => 'encrypted',
            'weekly_email_enabled' => 'boolean',
            'onboarded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Profession, $this> */
    public function profession(): BelongsTo
    {
        return $this->belongsTo(Profession::class);
    }

    /** @return HasMany<AppraisalPeriod, $this> */
    public function appraisalPeriods(): HasMany
    {
        return $this->hasMany(AppraisalPeriod::class)->orderByDesc('starts_on');
    }

    public function currentAppraisalPeriod(): ?AppraisalPeriod
    {
        return $this->appraisalPeriods()->where('is_current', true)->first();
    }

    /** @return HasMany<InboxItem, $this> */
    public function inboxItems(): HasMany
    {
        return $this->hasMany(InboxItem::class);
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<Recurrence, $this> */
    public function recurrences(): HasMany
    {
        return $this->hasMany(Recurrence::class);
    }

    /** @return HasMany<IgnoreRule, $this> */
    public function ignoreRules(): HasMany
    {
        return $this->hasMany(IgnoreRule::class);
    }

    /** @return HasMany<CalendarFeed, $this> */
    public function calendarFeeds(): HasMany
    {
        return $this->hasMany(CalendarFeed::class);
    }

    /** @return HasMany<GeneratedReport, $this> */
    public function generatedReports(): HasMany
    {
        return $this->hasMany(GeneratedReport::class);
    }

    /** @return HasMany<AiGeneration, $this> */
    public function aiGenerations(): HasMany
    {
        return $this->hasMany(AiGeneration::class);
    }

    public function hasOnboarded(): bool
    {
        return $this->onboarded_at !== null;
    }

    /**
     * Premium gate for future Paddle plans. Everyone is premium during
     * the free beta; once billing starts this checks the subscription.
     */
    public function isPremium(): bool
    {
        return true;
    }

    /** The user's personal dump address, e.g. u_ab12cd34ef@in.cpddump.com. */
    public function inboundEmailAddress(): ?string
    {
        if (! $this->inbound_email_token) {
            return null;
        }

        return $this->inbound_email_token.'@'.config('cpd.inbound_email_domain');
    }

    public function ensureInboundEmailToken(): void
    {
        if ($this->inbound_email_token) {
            return;
        }

        do {
            $token = 'u_'.Str::lower(Str::random(10));
        } while (static::where('inbound_email_token', $token)->exists());

        $this->forceFill(['inbound_email_token' => $token])->save();
    }
}
