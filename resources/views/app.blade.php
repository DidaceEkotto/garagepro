<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        try {
            $siteMeta = \App\Models\PlatformSetting::siteSettings();
        } catch (\Throwable) {
            $siteMeta = [];
        }

        $isIndexable = request()->routeIs(
            'home',
            'contact.show',
            'register',
            'legal.terms',
            'legal.privacy',
            'marketplace.index',
            'marketplace.best_sellers',
            'marketplace.category',
            'marketplace.show'
        );

        $locale = app()->getLocale();
        $siteName = !empty($siteMeta['name']) && $siteMeta['name'] !== 'GaragePro' && $siteMeta['name'] !== 'Laravel'
            ? $siteMeta['name']
            : 'MboaCar';

        $frontDescriptions = [
            'fr' => 'MboaCar — Gérez, réparez et vendez. La plateforme ERP tout-en-un pour garages automobiles (réceptions, ordres de réparation, devis, stock, facturation) et Marketplace de pièces auto.',
            'en' => 'MboaCar — Manage, repair and sell. The all-in-one ERP platform for auto repair shops (receptions, repair orders, quotes, inventory, invoicing) and auto parts Marketplace.',
            'es' => 'MboaCar — Gestione, repare y venda. La plataforma ERP integral para talleres mecánicos y Marketplace de repuestos automotrices.',
            'pt' => 'MboaCar — Gerencie, repare e venda. A plataforma ERP completa para oficinas automotivas e Marketplace de peças auto.',
            'ar' => 'MboaCar — أدر، أصلح وبع. المنصة المتكاملة لإدارة ورش السيارات وسوق قطع الغيار.',
        ];

        $marketplaceDescriptions = [
            'fr' => 'Marketplace MboaCar — Achetez et vendez des pièces détachées automobiles neuves et d\'occasion auprès de garages vérifiés : freinage, moteur, suspension, filtres et accessoires.',
            'en' => 'MboaCar Marketplace — Buy and sell new and used auto parts from verified repair shops: brakes, engine, suspension, filters and accessories.',
            'es' => 'Marketplace MboaCar — Compre y venda repuestos automotrices nuevos y usados de talleres verificados: frenos, motor, suspensión, filtros y accesorios.',
            'pt' => 'Marketplace MboaCar — Compre e venda peças automotivas novas e usadas de oficinas verificadas: freios, motor, suspensão, filtros e acessórios.',
            'ar' => 'سوق MboaCar — اشترِ وبِع قطع غيار السيارات الجديدة والمستعملة من ورش معتمدة: المكابح، المحرك، الفلاتر والإكسسوارات.',
        ];

        $frontTitles = [
            'fr' => $siteName . ' — Gérez, Réparez, Vendez | ERP Garage & Marketplace Auto',
            'en' => $siteName . ' — Manage, Repair, Sell | Auto Shop ERP & Parts Marketplace',
            'es' => $siteName . ' — Gestione, Repare, Venda | ERP Taller y Marketplace Auto',
            'pt' => $siteName . ' — Gerencie, Repare, Venda | ERP Oficina e Marketplace Auto',
            'ar' => $siteName . ' — نظام إدارة الورش وسوق قطع غيار السيارات',
        ];

        $marketplaceTitles = [
            'fr' => 'Marketplace Pièces Auto — Pièces neuves & occasion | ' . $siteName,
            'en' => 'Auto Parts Marketplace — New & Used Parts | ' . $siteName,
            'es' => 'Marketplace de Repuestos Auto — Piezas nuevas y usadas | ' . $siteName,
            'pt' => 'Marketplace de Peças Auto — Peças novas e usadas | ' . $siteName,
            'ar' => 'سوق قطع غيار السيارات — قطع جديدة ومستعملة | ' . $siteName,
        ];
    @endphp

    @if($isIndexable)
        @php
            $baseUrl = rtrim(request()->getSchemeAndHttpHost() ?: config('app.url', url('/')), '/');

            $buildAbsoluteImageUrl = function (?string $path) use ($baseUrl): ?string {
                if (empty($path)) {
                    return null;
                }
                $path = trim($path);
                if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                    return $path;
                }
                $cleanPath = ltrim($path, '/');
                $encodedSegments = array_map('rawurlencode', explode('/', $cleanPath));
                return $baseUrl . '/' . implode('/', $encodedSegments);
            };

            $defaultImg = !empty($siteMeta['seo_image_url'])
                ? $siteMeta['seo_image_url']
                : ($buildAbsoluteImageUrl('assets/images/seo-banner.jpg') ?: asset('assets/images/logo.png'));

            $defaultDesc = $frontDescriptions[$locale] ?? $frontDescriptions['fr'];

            $formatText = function (?string $text, int $limit = 160) use ($defaultDesc): string {
                if (empty($text)) {
                    return $defaultDesc;
                }
                return \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($text))), $limit);
            };

            $seoTitle = $frontTitles[$locale] ?? $frontTitles['fr'];
            $seoDesc = $defaultDesc;
            $seoImg = $defaultImg;
            $ogType = 'website';

            try {
                if (request()->routeIs('marketplace.show')) {
                    $productParam = request()->route('product');
                    $seoProduct = \App\Models\Product::withoutTenantScope()
                        ->with(['category', 'subCategory'])
                        ->where('is_published', true)
                        ->where('slug', $productParam)
                        ->first();
                    if (! $seoProduct && is_string($productParam) && ctype_digit($productParam)) {
                        $seoProduct = \App\Models\Product::withoutTenantScope()
                            ->with(['category', 'subCategory'])
                            ->where('is_published', true)
                            ->find((int) $productParam);
                    }
                    if ($seoProduct) {
                        $seoTitle = $seoProduct->title . ' | Marketplace ' . $siteName;
                        $seoDesc = $formatText($seoProduct->description ?: ($marketplaceDescriptions[$locale] ?? $marketplaceDescriptions['fr']));
                        if (! empty($seoProduct->images) && is_array($seoProduct->images)) {
                            $seoImg = $buildAbsoluteImageUrl(route('marketplace.product.image', ['product' => $seoProduct->id, 'index' => 0], false)) ?: $defaultImg;
                        }
                        $ogType = 'product';
                    }
                } elseif (request()->routeIs('marketplace.category')) {
                    $catSlug = request()->route('categorySlug');
                    $cat = \App\Models\MarketplaceCategory::where('slug', $catSlug)->first();
                    $catTitle = $cat ? $cat->name : 'Catégorie';
                    $seoTitle = $catTitle . ' — Marketplace Pièces Auto | ' . $siteName;
                    $seoDesc = $marketplaceDescriptions[$locale] ?? $marketplaceDescriptions['fr'];
                } elseif (request()->routeIs('marketplace.best_sellers')) {
                    $seoTitle = 'Bons Plans & Meilleures Ventes Pièces Auto | ' . $siteName;
                    $seoDesc = $marketplaceDescriptions[$locale] ?? $marketplaceDescriptions['fr'];
                } elseif (request()->routeIs('marketplace.index')) {
                    $seoTitle = $marketplaceTitles[$locale] ?? $marketplaceTitles['fr'];
                    $seoDesc = $marketplaceDescriptions[$locale] ?? $marketplaceDescriptions['fr'];
                } elseif (request()->routeIs('contact.show')) {
                    $seoTitle = 'Contact | ' . $siteName;
                    $seoDesc = $defaultDesc;
                } elseif (request()->routeIs('register')) {
                    $seoTitle = 'Créer votre espace garage | ' . $siteName;
                    $seoDesc = $defaultDesc;
                } elseif (request()->routeIs('legal.terms')) {
                    $seoTitle = 'Conditions Générales d\'Utilisation | ' . $siteName;
                    $seoDesc = $defaultDesc;
                } elseif (request()->routeIs('legal.privacy')) {
                    $seoTitle = 'Politique de Confidentialité | ' . $siteName;
                    $seoDesc = $defaultDesc;
                }
            } catch (\Throwable) {
                // Safe fallback to defaults
            }

            $seoImgExt = strtolower(pathinfo(parse_url($seoImg, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            $seoImgMime = match ($seoImgExt) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                default => 'image/jpeg',
            };
        @endphp

        <title inertia>{{ $seoTitle }}</title>
        <meta name="robots" content="index, follow">
        <link rel="canonical" href="{{ url()->current() }}">
        <meta name="description" head-key="description" content="{{ $seoDesc }}">
        <meta name="keywords" head-key="keywords" content="{{ !empty($siteMeta['keywords']) ? $siteMeta['keywords'] : 'mboacar, garage, automobile, pièces détachées, marketplace, ERP garage, Afrique, Cameroun' }}">
        <meta property="og:type" head-key="og:type" content="{{ $ogType }}">
        <meta property="og:site_name" head-key="og:site_name" content="{{ $siteName }}">
        <meta property="og:title" head-key="og:title" content="{{ $seoTitle }}">
        <meta property="og:description" head-key="og:description" content="{{ $seoDesc }}">
        <meta property="og:url" head-key="og:url" content="{{ url()->current() }}">
        <meta property="og:image" head-key="og:image" content="{{ $seoImg }}">
        <meta property="og:image:secure_url" head-key="og:image:secure_url" content="{{ $seoImg }}">
        <meta property="og:image:type" content="{{ $seoImgMime }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta itemprop="name" content="{{ $seoTitle }}">
        <meta itemprop="description" content="{{ $seoDesc }}">
        <meta itemprop="image" content="{{ $seoImg }}">
        <link rel="image_src" href="{{ $seoImg }}">
        <meta name="twitter:card" head-key="twitter:card" content="summary_large_image">
        <meta name="twitter:title" head-key="twitter:title" content="{{ $seoTitle }}">
        <meta name="twitter:description" head-key="twitter:description" content="{{ $seoDesc }}">
        <meta name="twitter:image" head-key="twitter:image" content="{{ $seoImg }}">
    @else
        <title inertia>{{ $siteName }}</title>
        <meta name="robots" content="noindex, nofollow">
    @endif

    <link rel="icon" href="{{ !empty($siteMeta['favicon_url']) ? $siteMeta['favicon_url'] : asset('assets/images/logo.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css" rel="stylesheet"/>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/js/select2.min.js" defer></script>
    @inertiaHead
</head>
<body class="bg-light">
    @inertia
</body>
</html>
