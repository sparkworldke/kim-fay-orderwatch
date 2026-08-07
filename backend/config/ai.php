<?php

return [
    /*
    | Preference order for the first configured LLM provider.
    | Supported: openai, xai, anthropic
    */
    'provider_order' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AI_PROVIDER_ORDER', 'openai,xai,anthropic')),
    ))),

    'openai_model' => env('AI_OPENAI_MODEL', 'gpt-4o-mini'),
    'xai_model' => env('AI_XAI_MODEL', 'grok-4.5'),
    'anthropic_model' => env('AI_ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),

    'http_timeout_seconds' => (int) env('AI_HTTP_TIMEOUT_SECONDS', 120),
    'max_tokens' => (int) env('AI_MAX_TOKENS', 1800),
    'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2'),
    'image_quality' => env('OPENAI_IMAGE_QUALITY', 'medium'),
    'image_timeout_seconds' => (int) env('OPENAI_IMAGE_TIMEOUT', 150),

    /**
     * When false (default), explicit Generate fails if no key / provider error
     * instead of silently writing a template briefing as if it were AI.
     */
    'allow_template_fallback' => filter_var(env('AI_ALLOW_TEMPLATE_FALLBACK', false), FILTER_VALIDATE_BOOLEAN),

    'genius' => [
        'timezone' => env('AI_GENIUS_TZ', 'Africa/Nairobi'),
        /** monday|sunday — week_start is the first day of the week in that TZ */
        'week_start' => env('AI_GENIUS_WEEK_START', 'monday'),
    ],
];
