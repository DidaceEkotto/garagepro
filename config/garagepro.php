<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Domaine de base pour les sous-domaines des tenants
    |--------------------------------------------------------------------------
    */
    'tenant_base_domain' => env('TENANT_BASE_DOMAIN', 'mboacar.com'),
    'whatsapp_public_url' => env('WHATSAPP_PUBLIC_URL'),

    /*
    |--------------------------------------------------------------------------
    | Sous-domaines réservés (interdits à l'inscription)
    |--------------------------------------------------------------------------
    */
    'reserved_subdomains' => [
        'www',
        'admin',
        'api',
        'app',
        'mail',
        'smtp',
        'imap',
        'pop',
        'ftp',
        'cpanel',
        'webmail',
        'support',
        'help',
        'billing',
        'docs',
        'status',
        'cdn',
        'assets',
        'static',
        'media',
        'root',
        'system',
        'test',
        'staging',
        'dev',
        'demo',
        'garagepro',
        'mboacar',
    ],

    /*
    |--------------------------------------------------------------------------
    | Paramètres commerciaux et fiscaux par défaut (Cameroun / OHADA)
    |--------------------------------------------------------------------------
    */
    'currency' => 'XAF',
    'currency_label' => 'FCFA',
    'default_subscription_price' => 10000, // 10 000 FCFA / mois
    'default_structure_email' => 'jeannotgates@gmail.com',
    'trial_days' => 7,
    'grace_period_days' => 5,
    'default_vat_rate' => 19.25, // TVA Cameroun (17,5% + 10% CAC = 19,25%)

    /*
    |--------------------------------------------------------------------------
    | Proxys de confiance (TrustProxies) pour X-Forwarded-For
    |--------------------------------------------------------------------------
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', null),

    /*
    |--------------------------------------------------------------------------
    | Sécurité : blocage automatique d'IP
    |--------------------------------------------------------------------------
    */
    'security' => [
        'max_login_attempts' => 5,
        'login_lockout_minutes' => 10,
        'auto_block_minutes' => 20,
        'auto_block_hours' => 1,
        'rate_limit_429_threshold' => 10,
        'ddos_max_requests_per_5s' => 45,
    ],

    /*
    |--------------------------------------------------------------------------
    | Langues supportées (avec drapeau et direction)
    |--------------------------------------------------------------------------
    */
    'supported_locales' => [
        'fr' => ['code' => 'fr', 'label' => 'Français', 'flag' => '🇫🇷', 'dir' => 'ltr'],
        'en' => ['code' => 'en', 'label' => 'English', 'flag' => '🇬🇧', 'dir' => 'ltr'],
        'es' => ['code' => 'es', 'label' => 'Español', 'flag' => '🇪🇸', 'dir' => 'ltr'],
        'pt' => ['code' => 'pt', 'label' => 'Português', 'flag' => '🇵🇹', 'dir' => 'ltr'],
        'ar' => ['code' => 'ar', 'label' => 'العربية', 'flag' => '🇸🇦', 'dir' => 'rtl'],
        'zh' => ['code' => 'zh', 'label' => '中文', 'flag' => '🇨🇳', 'dir' => 'ltr'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Liste des 54 pays d'Afrique (ISO 3166-1 alpha-2)
    |--------------------------------------------------------------------------
    */
    'african_countries' => [
        'ZA' => 'Afrique du Sud',
        'DZ' => 'Algérie',
        'AO' => 'Angola',
        'BJ' => 'Bénin',
        'BW' => 'Botswana',
        'BF' => 'Burkina Faso',
        'BI' => 'Burundi',
        'CM' => 'Cameroun',
        'CV' => 'Cap-Vert',
        'CF' => 'République centrafricaine',
        'KM' => 'Comores',
        'CG' => 'Congo (Brazzaville)',
        'CD' => 'République démocratique du Congo',
        'CI' => 'Côte d\'Ivoire',
        'DJ' => 'Djibouti',
        'EG' => 'Égypte',
        'ER' => 'Érythrée',
        'SZ' => 'Eswatini',
        'ET' => 'Éthiopie',
        'GA' => 'Gabon',
        'GM' => 'Gambie',
        'GH' => 'Ghana',
        'GN' => 'Guinée',
        'GW' => 'Guinée-Bissau',
        'GQ' => 'Guinée équatoriale',
        'KE' => 'Kenya',
        'LS' => 'Lesotho',
        'LR' => 'Libéria',
        'LY' => 'Libye',
        'MG' => 'Madagascar',
        'MW' => 'Malawi',
        'ML' => 'Mali',
        'MA' => 'Maroc',
        'MU' => 'Maurice',
        'MR' => 'Mauritanie',
        'MZ' => 'Mozambique',
        'NA' => 'Namibie',
        'NE' => 'Niger',
        'NG' => 'Nigéria',
        'UG' => 'Ouganda',
        'RW' => 'Rwanda',
        'ST' => 'Sao Tomé-et-Principe',
        'SN' => 'Sénégal',
        'SC' => 'Seychelles',
        'SL' => 'Sierra Leone',
        'SO' => 'Somalie',
        'SD' => 'Soudan',
        'SS' => 'Soudan du Sud',
        'TZ' => 'Tanzanie',
        'TD' => 'Tchad',
        'TG' => 'Togo',
        'TN' => 'Tunisie',
        'ZM' => 'Zambie',
        'ZW' => 'Zimbabwe',
    ],
];
