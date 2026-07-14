<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI (AI Manager)
    |--------------------------------------------------------------------------
    |
    | Powers the in-app AI Manager admin assistant. Uses OpenAI's Responses
    | API with function/tool calling so the model can read live
    | site data (read-only tools, auto-executed) and *propose* mutating admin
    | actions that require explicit human approval before they run.
    |
    */
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        // Hard ceilings so a runaway conversation can't rack up spend.
        'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 1500),
        'max_tool_iterations' => (int) env('OPENAI_MAX_TOOL_ITERATIONS', 6),
        'max_history_messages' => (int) env('OPENAI_MAX_HISTORY_MESSAGES', 80),
        'temperature' => (float) env('OPENAI_TEMPERATURE', 0.2),
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
        // Platform-wide cap on AI Manager turns (user messages) per calendar
        // day, to bound OpenAI spend. 0 = unlimited. Shown in the chat header.
        'daily_message_limit' => (int) env('AI_DAILY_MESSAGE_LIMIT', 0),
        // How many times to retry a transient OpenAI failure before giving up.
        'max_retries' => (int) env('OPENAI_MAX_RETRIES', 2),
        // Generate a short model-written title for each new conversation.
        'auto_title' => (bool) env('AI_AUTO_TITLE', true),
    ],

];
