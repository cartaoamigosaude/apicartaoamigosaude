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
    | CAS — URLs Internas
    |--------------------------------------------------------------------------
    */
    'cas' => [
        'api_url' => env('CAS_API_URL', 'https://api.cartaoamigosaude.com.br'),
        'crm_url' => env('CAS_CRM_URL', 'https://crm.cartaoamigosaude.com.br'),
    ],

    /*
    |--------------------------------------------------------------------------
    | APIs Externas
    |--------------------------------------------------------------------------
    */
    'celcash' => [
        'api_url' => env('CELCASH_API_URL', 'https://api-celcash.celcoin.com.br/v2'),
        'sync_enabled' => env('CELCASH_SYNC_ENABLED', true),
        'sync_atualizar' => env('CELCASH_SYNC_ATUALIZAR', 'S'),
        'sync_days_frequent' => env('CELCASH_SYNC_DAYS_FREQUENT', 0),
        'sync_days_hourly' => env('CELCASH_SYNC_DAYS_HOURLY', 3),
    ],

    'chathot' => [
        'api_url' => env('CHATHOT_API_URL', 'https://api.chathot.com.br'),
    ],

    'conexa' => [
        'api_url' => env('CONEXA_API_URL', 'https://api.conexasaude.com.br'),
        'api_url_hml' => env('CONEXA_API_URL_HML', 'https://hml-api.conexasaude.com.br'),
    ],

    'sabemi' => [
        'api_url' => env('SABEMI_API_URL', 'https://universoseguros.sabemi.com.br/SabemiWebApiV2'),
        'api_url_hml' => env('SABEMI_API_URL_HML', 'https://universoseguros.sabemi.com.br/SabemiHomologWebApiV2'),
    ],

    'epharma' => [
        'api_url' => env('EPHARMA_API_URL', 'https://rest.epharma.com.br'),
    ],

    'cartao_tem' => [
        'api_url' => env('CARTAO_TEM_API_URL', 'https://api.tempravoce.com'),
        'api_url_auth' => env('CARTAO_TEM_AUTH_URL', 'https://api.dd.meutem.com.br'),
    ],

    'clubecerto' => [
        'api_url' => env('CLUBECERTO_API_URL', 'https://node.clubecerto.com.br'),
    ],

    'kolmeya' => [
        'api_url' => env('KOLMEYA_API_URL', 'https://kolmeya.com.br'),
    ],

];
