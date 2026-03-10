<?php

$socialiteVerify = filter_var(env('SOCIALITE_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
$socialiteVerify = $socialiteVerify ?? true;

$socialiteCaBundle = env('SOCIALITE_CA_BUNDLE', env('GEMINI_CA_BUNDLE'));

$socialiteGuzzle = [
    'verify' => $socialiteVerify,
];

if (is_string($socialiteCaBundle) && $socialiteCaBundle !== '' && is_readable($socialiteCaBundle)) {
    $socialiteGuzzle['curl'] = [
        CURLOPT_CAINFO => $socialiteCaBundle,
    ];
}

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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'guzzle' => $socialiteGuzzle,
    ],

    'google_vision' => [
        'api_key' => env('GOOGLE_VISION_API_KEY'),
        'project_id' => 'fitandsleekpro',
        'credentials_path' => storage_path('credentials/google-vision-sa.json'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
        'guzzle' => $socialiteGuzzle,
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_FROM'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        // Default to the multimodal embedding model for image search
        'model' => env('GEMINI_MODEL', 'multimodal-embedding-001'),
        // Optional: path to a CA bundle (PEM). Example: E:\\certs\\cacert.pem
        'ca_bundle' => env('GEMINI_CA_BUNDLE'),
        // Optional: set to false to skip SSL verification (not recommended for prod)
        'verify' => env('GEMINI_VERIFY_SSL', true),
    ],

    'bakong' => [
        'base_url' => env('BAKONG_BASE_URL', 'https://api-bakong.nbc.gov.kh'),
        'token' => env('BAKONG_TOKEN'),
        'merchant_name' => env('BAKONG_MERCHANT_NAME'),
        'merchant_city' => env('BAKONG_MERCHANT_CITY', 'Phnom Penh'),
        'receive_account' => env('BAKONG_RECEIVE_ACCOUNT'),
        'node_binary' => env('KHQR_NODE_BINARY', env('NODE_BINARY', 'node')),
        'currency' => env('BAKONG_CURRENCY', 'KHR'),
        'expired_in' => (int) env('BAKONG_EXPIRES_IN', 300),
        'webhook_secret' => env('BAKONG_WEBHOOK_SECRET'),
        'verify' => env('BAKONG_VERIFY_SSL', true),
        'ca_bundle' => env('BAKONG_CA_BUNDLE', env('GEMINI_CA_BUNDLE')),
    ],

    'aba_payway' => [
        'merchant_id' => env('ABA_PAYWAY_MERCHANT_ID'),
        'api_key' => env('ABA_PAYWAY_API_KEY'),
        'api_secret' => env('ABA_PAYWAY_API_SECRET'),
        'return_url' => env('ABA_PAYWAY_RETURN_URL'),
        'callback_url' => env('ABA_PAYWAY_CALLBACK_URL'),
        'payment_link' => env('ABA_PAYWAY_PAYMENT_LINK'),
    ],

    'image_search' => [
        // Replicate for CLIP embeddings
        'replicate_api_token' => env('REPLICATE_API_TOKEN'),
        'replicate_model_version' => env('REPLICATE_MODEL_VERSION', 'ad59096144d657962460614f24168079083c500758406f529606869403a557b3'),

        // Optional API key for local/HF CLIP endpoint protection
        'ai_service_key' => env('AI_SERVICE_KEY', 'my_super_secret_123'),

        // Cohere text embeddings
        'cohere_api_key' => env('COHERE_API_KEY'),
        'cohere_model' => env('COHERE_MODEL', 'embed-multilingual-v3.0'),

        // Local CLIP (FastAPI) image embeddings
        'local_clip_endpoint' => env('LOCAL_CLIP_ENDPOINT', 'http://127.0.0.1:8000/embed'),
        'local_clip_vector_size' => (int) env('LOCAL_CLIP_VECTOR_SIZE', 512),

        // Groq vision (LLaVA) for image-to-text captions
        'groq_api_key' => env('GROQ_API_KEY'),
        'groq_image_model' => env('GROQ_IMAGE_MODEL', 'llama-3.2-11b-vision-preview'),

        'huggingface_api_key' => env('HUGGINGFACE_API_TOKEN'),
        'huggingface_model' => env('HUGGINGFACE_MODEL', 'sentence-transformers/all-MiniLM-L6-v2'),
        'huggingface_endpoint_url' => env('HUGGINGFACE_ENDPOINT_URL'),

        // Qdrant Cloud vector search
        'qdrant_url' => env('QDRANT_URL', 'http://localhost:6333'),
        'qdrant_api_key' => env('QDRANT_API_KEY'),
        'qdrant_collection' => env('QDRANT_COLLECTION', 'products_cohere_1024'),
        'qdrant_vector_size' => (int) env('QDRANT_VECTOR_SIZE', 1024),
        // Default 0.7 keeps results reasonably tight; override via env if needed.
        'qdrant_score_threshold' => env('QDRANT_SCORE_THRESHOLD', 0.7),

        'timeout' => (int) env('IMAGE_SEARCH_TIMEOUT', 120),
    ],

];
