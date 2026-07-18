<?php

namespace App\Services;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Models\AiGeneration;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

/**
 * Single entry point for every AI call. Resolves the effective provider,
 * model and API key for a user (their own key, else the platform key),
 * applies the key for the duration of the call only, and records usage.
 */
class AiGateway
{
    public function __construct(private AiManager $manager) {}

    /**
     * Invoke an agent for a user, using their own API key when configured.
     *
     * @param  array<int, mixed>  $attachments
     */
    public function prompt(
        Agent $agent,
        User $user,
        AiPurpose $purpose,
        string $prompt,
        array $attachments = [],
        ?Model $generatable = null,
    ): AgentResponse {
        $provider = $this->providerFor($user);
        $model = $this->modelFor($provider, $purpose);
        $usesUserKey = $user->ai_api_key !== null;

        $invoke = fn () => $agent->prompt($prompt, $attachments, provider: $provider->value, model: $model);

        $response = $usesUserKey
            ? $this->withUserKey($provider, $user->ai_api_key, $invoke)
            : $invoke();

        AiGeneration::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'provider' => $provider->value,
            'model' => $model,
            'input_tokens' => $response->usage->promptTokens,
            'output_tokens' => $response->usage->completionTokens,
            'used_user_key' => $usesUserKey,
            'generatable_type' => $generatable?->getMorphClass(),
            'generatable_id' => $generatable?->getKey(),
        ]);

        return $response;
    }

    /**
     * Invoke a structured-output agent and return the validated response.
     *
     * @param  array<int, mixed>  $attachments
     */
    public function structuredPrompt(
        Agent $agent,
        User $user,
        AiPurpose $purpose,
        string $prompt,
        array $attachments = [],
        ?Model $generatable = null,
    ): StructuredAgentResponse {
        $response = $this->prompt($agent, $user, $purpose, $prompt, $attachments, $generatable);

        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('Expected a structured response from '.$agent::class);
        }

        return $response;
    }

    /** The provider used for this user's text generation. */
    public function providerFor(User $user): AiProvider
    {
        if ($user->ai_api_key !== null && $user->ai_provider !== null) {
            return $user->ai_provider;
        }

        return AiProvider::from(config('ai.default'));
    }

    public function modelFor(AiProvider $provider, AiPurpose $purpose): string
    {
        return config("cpd.ai.models.{$provider->value}.{$purpose->value}");
    }

    /**
     * Has this user exhausted the platform-key daily token budgets
     * (output or input), or has the platform-wide ceiling been hit?
     * Users on their own key are never budget-limited.
     */
    public function overDailyBudget(User $user): bool
    {
        if ($user->ai_api_key !== null) {
            return false;
        }

        $spent = AiGeneration::query()
            ->where('user_id', $user->id)
            ->where('used_user_key', false)
            ->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('coalesce(sum(output_tokens), 0) as output_sum')
            ->selectRaw('coalesce(sum(input_tokens), 0) as input_sum')
            ->toBase()
            ->first();

        if ($spent !== null
            && ((int) $spent->output_sum >= config('cpd.ai.daily_token_budget')
                || (int) $spent->input_sum >= config('cpd.ai.daily_input_token_budget'))) {
            return true;
        }

        return $this->platformOverDailyBudget();
    }

    /**
     * Total platform-key spend (input + output) across all users today
     * has hit the global ceiling — the multi-account abuse kill-switch.
     */
    public function platformOverDailyBudget(): bool
    {
        $total = (int) AiGeneration::query()
            ->where('used_user_key', false)
            ->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('coalesce(sum(input_tokens), 0) + coalesce(sum(output_tokens), 0) as total')
            ->value('total');

        return $total >= config('cpd.ai.platform_daily_token_budget');
    }

    /**
     * Run a callback with the user's API key swapped into the provider
     * config, restoring the platform key afterwards. The provider
     * instance cache is purged on both sides so the key actually
     * applies — never rely on ambient state in queued jobs.
     */
    private function withUserKey(AiProvider $provider, string $key, callable $callback): mixed
    {
        $configKey = "ai.providers.{$provider->value}.key";
        $original = config($configKey);

        config([$configKey => $key]);
        $this->manager->forgetInstance($provider->value);

        try {
            return $callback();
        } finally {
            config([$configKey => $original]);
            $this->manager->forgetInstance($provider->value);
        }
    }
}
