<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Partner credential
    |--------------------------------------------------------------------------
    | The client id ("partner key") issued by the Truecaller developer portal.
    | It is the only client authentication in the PKCE code-exchange flow, so
    | treat it as a secret: no default, and never log it -- not even hashed.
    */
    'client_id' => env('TRUECALLER_CLIENT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Region / hosts
    |--------------------------------------------------------------------------
    | Truecaller runs separate OAuth stacks for EU and non-EU users.
    |
    | VERIFY these hosts and paths against the Truecaller developer portal
    | before enabling in production. They are the only values in this package
    | that cannot be derived from the integrating codebase, which is exactly
    | why each is env-overridable: confirming them is an ops change, not a
    | code change.
    */
    'region' => env('TRUECALLER_REGION', 'noneu'),

    'hosts' => [
        'noneu' => env('TRUECALLER_OAUTH_HOST_NONEU', 'https://oauth-account-noneu.truecaller.com'),
        'eu'    => env('TRUECALLER_OAUTH_HOST_EU', 'https://oauth-account-eu.truecaller.com'),
    ],

    'endpoints' => [
        'token'    => env('TRUECALLER_TOKEN_PATH', '/v1/token'),
        'userinfo' => env('TRUECALLER_USERINFO_PATH', '/v1/userinfo'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP timeouts, seconds
    |--------------------------------------------------------------------------
    | A login makes two calls, so the worst case is roughly twice the request
    | timeout. Callers should allow for that on the client side.
    |
    | connect_timeout is applied through withOptions() rather than
    | PendingRequest::connectTimeout(), which does not exist on Laravel 8.
    */
    'http' => [
        'timeout'         => (int) env('TRUECALLER_HTTP_TIMEOUT', 15),
        'connect_timeout' => (int) env('TRUECALLER_HTTP_CONNECT_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Replay guard
    |--------------------------------------------------------------------------
    | An authorization code may be exchanged exactly once. The TTL must exceed
    | Truecaller's code lifetime (~10 min) so the guard never expires while the
    | code is still live at Truecaller.
    |
    | The key is the sha256 of the code, so a raw authorization code is never
    | readable in a cache dump or a KEYS scan.
    */
    'replay' => [
        'cache_store'      => env('TRUECALLER_CACHE_STORE'),
        'cache_key_prefix' => 'truecaller:code:',
        'ttl_seconds'      => (int) env('TRUECALLER_CODE_REPLAY_TTL', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | ISO alpha-2 -> dial code
    |--------------------------------------------------------------------------
    | Truecaller's userinfo returns phone_number_country_code as an ISO alpha-2
    | code ("IN"), but a phone number is normally stored against a dial code
    | ("91") plus a bare national number.
    |
    | giggsey/libphonenumber-for-php is deliberately NOT a dependency, so this
    | map is the whole country resolver. A country absent from it is REJECTED,
    | never guessed: a guessed dial code produces an E.164 string that the
    | number's real owner can never match, which silently duplicates the user.
    | Rejection is recoverable (fall back to OTP); guessing is not.
    |
    | Grow this map from PhoneUnsupportedException warnings in the log, not
    | from guesswork.
    */
    'iso_to_dial_code' => [
        // India and the diaspora markets that actually see traffic
        'IN' => '91',
        'US' => '1',   'CA' => '1',
        'GB' => '44',  'IE' => '353',
        'AE' => '971', 'SA' => '966', 'QA' => '974', 'KW' => '965',
        'OM' => '968', 'BH' => '973',
        'SG' => '65',  'MY' => '60',  'AU' => '61',  'NZ' => '64',
        'NP' => '977', 'LK' => '94',  'BD' => '880', 'PK' => '92',
        'BT' => '975', 'MV' => '960', 'AF' => '93',  'MM' => '95',
        // Europe
        'DE' => '49',  'FR' => '33',  'IT' => '39',  'ES' => '34',
        'NL' => '31',  'CH' => '41',  'SE' => '46',  'NO' => '47',
        'DK' => '45',  'FI' => '358', 'PL' => '48',  'PT' => '351',
        'AT' => '43',  'BE' => '32',  'CZ' => '420', 'GR' => '30',
        'TR' => '90',  'RU' => '7',
        // Africa and the Middle East
        'ZA' => '27',  'KE' => '254', 'TZ' => '255', 'UG' => '256',
        'NG' => '234', 'MU' => '230', 'EG' => '20',  'IL' => '972',
        'JO' => '962', 'LB' => '961', 'IQ' => '964',
        // Asia-Pacific
        'HK' => '852', 'TW' => '886', 'KR' => '82',  'JP' => '81',
        'CN' => '86',  'TH' => '66',  'ID' => '62',  'PH' => '63',
        'VN' => '84',
        // Americas
        'BR' => '55',  'MX' => '52',  'AR' => '54',  'CL' => '56',
    ],

    /*
    |--------------------------------------------------------------------------
    | National-number validation, per dial code
    |--------------------------------------------------------------------------
    | 'default' is intentionally permissive: it is the widest shape a national
    | number can take, so a normalised number can always be fed back into an
    | OTP flow that accepts /^[1-9][0-9]{5,12}$/.
    |
    | '91' is tightened to the Indian mobile series, because that is the one
    | market where a landline or a malformed number would otherwise sail
    | through and create an unreachable account.
    */
    'national_number_rules' => [
        '91'      => '/^[6-9][0-9]{9}$/',
        'default' => '/^[1-9][0-9]{5,12}$/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Log masking
    |--------------------------------------------------------------------------
    | Matched case-insensitively and recursively against array keys before
    | anything is handed to the logger. A bare "code" is included because
    | Truecaller echoes the authorization code back in some error bodies.
    */
    'logging' => [
        /*
         * Log channel for this package's protocol traffic. null uses the
         * application's default logger. Set it to keep the OAuth exchange in
         * a file of its own.
         */
        'channel' => env('TRUECALLER_LOG_CHANNEL'),

        'mask_keys' => [
            'authorization_code', 'authorizationcode',
            'code_verifier', 'codeverifier',
            'access_token', 'accesstoken',
            'refresh_token', 'refreshtoken',
            'id_token', 'idtoken',
            'client_id', 'clientid',
            'code',
            'phone_number', 'phonenumber',
            'email',
        ],
    ],
];
