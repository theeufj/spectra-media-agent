<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kill switch
    |--------------------------------------------------------------------------
    |
    | SUPPORT_CHAT_ENABLED=false hides the widget and refuses the endpoint,
    | without a deploy. This feature calls a paid AI model on user input and
    | emails every admin, so being able to switch it off from the environment is
    | what makes it safe to leave on.
    |
    */

    'enabled' => env('SUPPORT_CHAT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Abuse limits
    |--------------------------------------------------------------------------
    |
    | The endpoint is behind auth, which stops anonymous drive-by abuse but not
    | a logged-in customer (or a stuck client, which is far more likely) looping
    | on it. Each message costs a Gemini call, so the ceiling that matters is
    | the daily one: past it the ticket is still recorded and support still sees
    | the question, but the model is not called. Support degrades; the bill does
    | not run.
    |
    */

    'max_message_length' => (int) env('SUPPORT_CHAT_MAX_MESSAGE_LENGTH', 2000),

    // Past this a conversation is almost certainly not a support question any
    // more. The ticket stays open; the customer is asked to continue by email.
    'max_messages_per_conversation' => (int) env('SUPPORT_CHAT_MAX_MESSAGES', 20),

    // Hard per-user daily ceiling on AI calls. The cost control.
    'max_ai_replies_per_day' => (int) env('SUPPORT_CHAT_MAX_AI_PER_DAY', 30),

    // Open chat tickets one person may have at once, so the admin queue cannot
    // be flooded by opening a new conversation per message.
    'max_open_tickets_per_user' => (int) env('SUPPORT_CHAT_MAX_OPEN_TICKETS', 5),

];
