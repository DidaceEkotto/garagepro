<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Auth\PasswordResetOtpController;
use App\Http\Controllers\Auth\TenantAuthController;
use App\Http\Controllers\Auth\TenantRegistrationController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\GeocodeController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\PublicAppointmentController;
use App\Http\Controllers\PublicGarageController;
use App\Http\Controllers\PublicQuoteController;
use App\Http\Controllers\PublicReviewController;
use App\Http\Controllers\PublicSosController;
use App\Http\Controllers\PublicTrackingController;
use App\Http\Controllers\VehicleHistoryCheckController;
use App\Http\Controllers\WhatsappBotController;
use App\Http\Controllers\Tenant\AccountingController;
use App\Http\Controllers\Tenant\AffiliationController;
use App\Http\Controllers\Tenant\AfterSalesController;
use App\Http\Controllers\Tenant\AgencyController;
use App\Http\Controllers\Tenant\AiDiagnosticController;
use App\Http\Controllers\Tenant\AppointmentController;
use App\Http\Controllers\Tenant\B2bNetworkController;
use App\Http\Controllers\Tenant\CustomerController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\ExportController;
use App\Http\Controllers\Tenant\FleetController;
use App\Http\Controllers\Tenant\HrController;
use App\Http\Controllers\Tenant\InspectionController;
use App\Http\Controllers\Tenant\InsuranceController;
use App\Http\Controllers\Tenant\InventoryController;
use App\Http\Controllers\Tenant\InvoiceController;
use App\Http\Controllers\Tenant\MaintenanceReminderController;
use App\Http\Controllers\Tenant\MarketplaceManagementController;
use App\Http\Controllers\Tenant\MessageController;
use App\Http\Controllers\Tenant\PaymentController;
use App\Http\Controllers\Tenant\OfflineSyncController;
use App\Http\Controllers\Tenant\ProfessionalController;
use App\Http\Controllers\Tenant\ProfileController;
use App\Http\Controllers\Tenant\PurchaseOrderController;
use App\Http\Controllers\Tenant\QuoteController;
use App\Http\Controllers\Tenant\RepairOrderController;
use App\Http\Controllers\Tenant\ReportController;
use App\Http\Controllers\Tenant\RoadsideSosController;
use App\Http\Controllers\Tenant\SecureFileController;
use App\Http\Controllers\Tenant\ShowcaseController;
use App\Http\Controllers\Tenant\SpaceSettingsController;
use App\Http\Controllers\Tenant\SubcontractController;
use App\Http\Controllers\Tenant\TechnicalInspectionController;
use App\Http\Controllers\Tenant\ToolsAndBulkController;
use App\Http\Controllers\Tenant\VehicleController;
use App\Http\Controllers\Tenant\VehicleExitController;
use App\Http\Controllers\Tenant\VehicleReceptionController;
use App\Http\Controllers\Tenant\WorkshopController;
use App\Http\Controllers\Tenant\WorkshopPlanningController;
use App\Http\Controllers\Webhooks\SaspayWebhookController;
use App\Http\Middleware\LogoutDemoOnGuestAuth;
use App\Models\Partner;
use App\Models\PlatformSetting;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Page d'accueil marketing
Route::get('/', function () {
    return Inertia::render('Welcome', [
        'appName' => config('app.name', 'MboaCar'),
        'trialDays' => PlatformSetting::trialDays(),
        'monthlyPrice' => PlatformSetting::subscriptionPrice(),
        'baseDomain' => config('garagepro.tenant_base_domain', 'mboacar.com'),
        'testimonials' => Testimonial::published()->latest()->take(10)->get(),
        'partners' => Partner::active()->get(['id', 'name', 'logo_path', 'website_url']),
    ]);
})->name('home');

// Géocodage gratuit OpenStreetMap Nominatim (autocomplétion localisation & GPS)
Route::get('/api/geocode/search', [GeocodeController::class, 'search'])
    ->middleware('throttle:40,1')
    ->name('api.geocode.search');
Route::get('/api/geocode/reverse', [GeocodeController::class, 'reverse'])
    ->middleware('throttle:30,1')
    ->name('api.geocode.reverse');
Route::get('/api/geocode/route', [GeocodeController::class, 'route'])
    ->middleware('throttle:40,1')
    ->name('api.geocode.route');

// Page Contact
Route::get('/contact', [ContactController::class, 'show'])->name('contact.show');
Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('contact.store');

// Marketplace Public (Pièces Automobiles)
Route::get('/marketplace', [MarketplaceController::class, 'index'])->name('marketplace.index');
Route::get('/marketplace/bons-plans', [MarketplaceController::class, 'bestSellers'])->name('marketplace.best_sellers');
Route::get('/marketplace/api/search', [MarketplaceController::class, 'apiSearch'])
    ->middleware('throttle:60,1')
    ->name('marketplace.api.search');
Route::get('/marketplace/categorie/{categorySlug}/{subCategorySlug?}', [MarketplaceController::class, 'category'])->name('marketplace.category');
Route::get('/marketplace/produit/{product}', [MarketplaceController::class, 'show'])->name('marketplace.show');
Route::post('/marketplace/produit/{product}/avis', [MarketplaceController::class, 'storeReview'])
    ->middleware('throttle:10,1')
    ->name('marketplace.review.store');
Route::get('/marketplace/images/{product}/{index}', [MarketplaceController::class, 'image'])->name('marketplace.product.image');
Route::get('/marketplace/bannieres/{banner}', [MarketplaceController::class, 'bannerImage'])->name('marketplace.banner.image');
Route::get('/panier', [MarketplaceController::class, 'cart'])->name('marketplace.cart');
Route::post('/panier/ajouter/{product}', [MarketplaceController::class, 'addToCart'])->name('marketplace.cart.add');
Route::patch('/panier/{productId}', [MarketplaceController::class, 'updateCart'])->name('marketplace.cart.update');
Route::delete('/panier/{productId}', [MarketplaceController::class, 'removeFromCart'])->name('marketplace.cart.remove');
Route::get('/commander', [MarketplaceController::class, 'checkout'])->name('marketplace.checkout');
Route::post('/commander', [MarketplaceController::class, 'placeOrder'])
    ->middleware('throttle:15,1')
    ->name('marketplace.order.place');

Route::get('/conditions-utilisation', function () {
    return Inertia::render('Legal/Show', [
        'title' => 'Conditions d\'utilisation',
        'slug' => 'terms',
        'content' => \App\Services\HtmlSanitizer::clean(\App\Models\PlatformSetting::termsOfUse()),
    ]);
})->name('legal.terms');

Route::get('/politique-confidentialite', function () {
    return Inertia::render('Legal/Show', [
        'title' => 'Politique de confidentialité',
        'slug' => 'privacy',
        'content' => \App\Services\HtmlSanitizer::clean(\App\Models\PlatformSetting::privacyPolicy()),
    ]);
})->name('legal.privacy');

// Mode Sandbox Démo sans inscription
Route::get('/demo', [DemoController::class, 'enter'])
    ->middleware('throttle:10,5')
    ->name('demo.enter');
Route::post('/demo/reinitialiser', [DemoController::class, 'reset'])
    ->middleware('throttle:3,5')
    ->name('demo.reset');
Route::get('/demo/quitter', [DemoController::class, 'exitAndRedirect'])->name('demo.exit');

// Carnet d'entretien numérique & Suivi client en direct
Route::get('/carnet/{token}', [PublicTrackingController::class, 'vehiclePassport'])->name('public.vehicle.passport');
Route::get('/pv-visite/{token}', [TechnicalInspectionController::class, 'publicShow'])->name('public.technical_pv.show');
Route::get('/pv-visite/{token}/pdf', [TechnicalInspectionController::class, 'publicPdf'])
    ->middleware('throttle:pdf')
    ->name('public.technical_pv.pdf');
Route::post('/pv-visite/{token}/demander-devis', [TechnicalInspectionController::class, 'requestRepairQuote'])
    ->middleware('throttle:10,1')
    ->name('public.technical_pv.request_quote');
Route::get('/suivi/{token}', [PublicTrackingController::class, 'repairOrderTracking'])->name('public.repair_order.tracking');
Route::post('/suivi/{token}/devis/{quoteId}/repondre', [PublicTrackingController::class, 'respondQuote'])->name('public.repair_order.quote_respond');
Route::get('/devis-public/{token}', [PublicQuoteController::class, 'show'])->name('public.quote.show');
Route::post('/devis-public/{token}/repondre', [PublicQuoteController::class, 'respond'])
    ->middleware('throttle:20,1')
    ->name('public.quote.respond');
Route::get('/rdv/{token}', [PublicAppointmentController::class, 'show'])->name('public.appointment.show');
Route::post('/rdv/{token}', [PublicAppointmentController::class, 'submit'])
    ->middleware('throttle:10,1')
    ->name('public.appointment.submit');
Route::get('/avis/{token}', [PublicReviewController::class, 'show'])->name('public.review.show');
Route::post('/avis/{token}', [PublicReviewController::class, 'submit'])
    ->middleware('throttle:15,1')
    ->name('public.review.submit');

// SOS Dépannage & Remorquage Géolocalisé (OpenStreetMap — public sans inscription)
Route::get('/sos-depannage', [PublicSosController::class, 'index'])->name('public.sos.index');
Route::get('/sos-depannage/proximite', [PublicSosController::class, 'nearby'])
    ->middleware('throttle:40,1')
    ->name('public.sos.nearby');
Route::post('/sos-depannage', [PublicSosController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('public.sos.store');
Route::get('/sos-depannage/{token}', [PublicSosController::class, 'track'])->name('public.sos.track');
Route::post('/sos-depannage/{token}/offres/{offer}/accepter', [PublicSosController::class, 'acceptOffer'])
    ->middleware('throttle:15,1')
    ->name('public.sos.accept_offer');

// Vitrine publique d'un établissement + Prise de rendez-vous instantanée
Route::get('/garage/{slug}', [PublicGarageController::class, 'show'])->name('public.garage.show');
Route::post('/garage/{slug}/rendez-vous', [PublicGarageController::class, 'bookAppointment'])
    ->middleware('throttle:12,1')
    ->name('public.garage.book_appointment');
Route::get('/garage-media/vitrine/{tenant}/{index}', [PublicGarageController::class, 'showcaseImage'])
    ->whereNumber(['tenant', 'index'])
    ->name('public.garage.showcase_image');
Route::get('/garage-media/prestation/{service}', [PublicGarageController::class, 'serviceImage'])
    ->whereNumber('service')
    ->name('public.garage.service_image');

// MboaCar Check — Rapport Historique Anti-Fraude Véhicule d'Occasion + Scan QR Intelligent
Route::get('/verifier-vehicule', [VehicleHistoryCheckController::class, 'index'])->name('public.vehicle_check.index');
Route::get('/verifier-vehicule/pdf', [VehicleHistoryCheckController::class, 'pdf'])
    ->middleware('throttle:pdf')
    ->name('public.vehicle_check.pdf');
Route::get('/vehicule-scan/{token}', [VehicleHistoryCheckController::class, 'smartQrScan'])->name('public.vehicle.smart_scan');

// Webhook Réceptionniste IA WhatsApp 24/7 par garage (protégé par X-Bot-Secret)
Route::post('/webhooks/whatsapp-bot/{tenantSlug}', [WhatsappBotController::class, 'webhook'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->middleware('throttle:60,1')
    ->name('webhooks.whatsapp_bot');

// Portail Client Unique MboaCar (accès multi-garages par OTP WhatsApp)
Route::get('/mon-espace-client', [CustomerPortalController::class, 'index'])->name('public.customer_portal');
Route::post('/mon-espace-client/otp', [CustomerPortalController::class, 'requestOtp'])
    ->middleware('throttle:10,1')
    ->name('public.customer_portal.otp');
Route::post('/mon-espace-client/verifier', [CustomerPortalController::class, 'verifyOtp'])
    ->middleware('throttle:15,1')
    ->name('public.customer_portal.verify');
Route::put('/mon-espace-client/profil', [CustomerPortalController::class, 'updateProfile'])
    ->middleware('throttle:10,5')
    ->name('public.customer_portal.profile');
Route::post('/mon-espace-client/deconnexion', [CustomerPortalController::class, 'logout'])->name('public.customer_portal.logout');

// Changement de langue (fr, en, es, pt, ar, zh)
Route::post('/langue/{locale}', function (string $locale) {
    $supported = array_keys(config('garagepro.supported_locales', ['fr' => []]));
    if (! in_array($locale, $supported, true)) {
        abort(404);
    }
    session(['locale' => $locale]);

    return back()->withCookie(cookie('locale', $locale, 60 * 24 * 365));
})->name('locale.switch');

// Inscription nouveau garage + redirection SSO vers sous-domaine
Route::middleware([LogoutDemoOnGuestAuth::class, 'guest:web'])->group(function () {
    Route::get('/inscription', [TenantRegistrationController::class, 'show'])->name('register');
    Route::post('/inscription', [TenantRegistrationController::class, 'store'])
        ->middleware('throttle:register')
        ->name('register.store');
    Route::get('/inscription/verification-otp', [TenantRegistrationController::class, 'showOtp'])->name('register.otp');
    Route::post('/inscription/verification-otp', [TenantRegistrationController::class, 'verifyOtp'])->name('register.otp.verify');
    Route::post('/inscription/verification-otp/renvoyer', [TenantRegistrationController::class, 'resendOtp'])->name('register.otp.resend');
    Route::get('/inscription/mot-de-passe', [TenantRegistrationController::class, 'showPasswordStep'])->name('register.password');
    Route::post('/inscription/mot-de-passe', [TenantRegistrationController::class, 'storePasswordStep'])->name('register.password.store');
});

Route::get('/sso/{token}', [TenantRegistrationController::class, 'consumeSso'])->name('tenant.sso');

// Authentification Tenant (utilisateurs de garage)
Route::middleware([LogoutDemoOnGuestAuth::class, 'guest:web'])->group(function () {
    Route::get('/connexion', [TenantAuthController::class, 'showLogin'])->name('tenant.login');
    Route::post('/connexion', [TenantAuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('tenant.login.post');

    // Mot de passe oublié (réservé au propriétaire du compte — OTP E-mail ou WhatsApp)
    Route::get('/mot-de-passe-oublie', [PasswordResetOtpController::class, 'showRequest'])->name('password.request');
    Route::post('/mot-de-passe-oublie', [PasswordResetOtpController::class, 'sendOtp'])
        ->middleware('throttle:5,1')
        ->name('password.otp.send');
    Route::get('/mot-de-passe-oublie/verification-otp', [PasswordResetOtpController::class, 'showOtp'])->name('password.otp');
    Route::post('/mot-de-passe-oublie/verification-otp', [PasswordResetOtpController::class, 'verifyOtp'])
        ->middleware('throttle:10,1')
        ->name('password.otp.verify');
    Route::post('/mot-de-passe-oublie/verification-otp/renvoyer', [PasswordResetOtpController::class, 'resendOtp'])
        ->middleware('throttle:3,2')
        ->name('password.otp.resend');
    Route::get('/mot-de-passe-oublie/nouveau-mot-de-passe', [PasswordResetOtpController::class, 'showResetForm'])->name('password.reset.form');
    Route::post('/mot-de-passe-oublie/nouveau-mot-de-passe', [PasswordResetOtpController::class, 'updatePassword'])
        ->middleware('throttle:5,1')
        ->name('password.reset.update');
});

Route::post('/deconnexion', [TenantAuthController::class, 'logout'])
    ->middleware('auth:web')
    ->name('tenant.logout');

Route::middleware(['tenant.auth', 'tenant.writable'])->group(function () {
    Route::get('/tableau-de-bord', [DashboardController::class, 'index'])->name('tenant.dashboard');
    Route::get('/tutoriel', fn () => \Inertia\Inertia::render('Tenant/Tutorial/Index'))->name('tenant.tutorial');
    Route::get('/marketplace-b2b/rechercher', [MarketplaceController::class, 'b2bSearch'])->name('tenant.marketplace.b2b_search');

    Route::get('/agences', [AgencyController::class, 'index'])->name('tenant.agencies.index');
    Route::post('/agences', [AgencyController::class, 'store'])->name('tenant.agencies.store');
    Route::put('/agences/{agency}', [AgencyController::class, 'update'])->name('tenant.agencies.update');
    Route::post('/agences/{agency}/activer', [AgencyController::class, 'switchAgency'])->name('tenant.agencies.switch');

    // Professionnels, Mon Profil & Paramètres Espace
    Route::middleware('tenant.permission:users.view|users.manage')->group(function () {
        Route::get('/professionnels', [ProfessionalController::class, 'index'])->name('tenant.professionals.index');
        Route::post('/professionnels', [ProfessionalController::class, 'store'])->name('tenant.professionals.store');
        Route::put('/professionnels/{professional}', [ProfessionalController::class, 'update'])->name('tenant.professionals.update');
        Route::post('/professionnels/{professional}/statut', [ProfessionalController::class, 'toggleActive'])->name('tenant.professionals.toggle');
        Route::post('/professionnels/{professional}/reinitialiser-mot-de-passe', [ProfessionalController::class, 'resetPassword'])->name('tenant.professionals.reset_password');
    });

    Route::get('/mon-profil', [ProfileController::class, 'index'])->name('tenant.profile.index');
    Route::put('/mon-profil', [ProfileController::class, 'update'])->name('tenant.profile.update');
    Route::put('/mon-profil/mot-de-passe', [ProfileController::class, 'updatePassword'])->name('tenant.profile.password');

    Route::middleware('tenant.permission:settings.manage')->group(function () {
        Route::get('/parametres-espace', [SpaceSettingsController::class, 'index'])->name('tenant.settings.index');
        Route::post('/parametres-espace', [SpaceSettingsController::class, 'update'])->name('tenant.settings.update');
        Route::post('/parametres-espace/bot-whatsapp/tester', [WhatsappBotController::class, 'simulate'])
            ->middleware('throttle:20,1')
            ->name('tenant.settings.whatsapp_bot_simulate');
    });

    // SOS Dépannage côté Garage
    Route::get('/depannage-sos', [RoadsideSosController::class, 'index'])->name('tenant.sos.index');
    Route::post('/depannage-sos/{sosRequest}/offre', [RoadsideSosController::class, 'sendOffer'])->name('tenant.sos.offer');
    Route::patch('/depannage-sos/{sosRequest}/statut', [RoadsideSosController::class, 'updateMissionStatus'])->name('tenant.sos.status');

    // Ma Vitrine & Prestations (photos établissement + services avec image facultative)
    Route::get('/vitrine-prestations', [ShowcaseController::class, 'index'])->name('tenant.showcase.index');
    Route::post('/vitrine-prestations/photos', [ShowcaseController::class, 'uploadPhotos'])->name('tenant.showcase.photos.upload');
    Route::delete('/vitrine-prestations/photos', [ShowcaseController::class, 'deletePhoto'])->name('tenant.showcase.photos.delete');
    Route::post('/vitrine-prestations/prestations', [ShowcaseController::class, 'storeService'])->name('tenant.showcase.services.store');
    Route::post('/vitrine-prestations/prestations/{service}', [ShowcaseController::class, 'updateService'])->name('tenant.showcase.services.update');
    Route::delete('/vitrine-prestations/prestations/{service}', [ShowcaseController::class, 'deleteService'])->name('tenant.showcase.services.delete');

    // Assistant IA Diagnostic Automobile (Gemini — isolé par tenant)
    Route::get('/assistant-ia', [AiDiagnosticController::class, 'index'])->name('tenant.ai_diagnostic.index');
    Route::post('/assistant-ia/messages', [AiDiagnosticController::class, 'send'])
        ->middleware('throttle:30,1')
        ->name('tenant.ai_diagnostic.send');
    Route::post('/assistant-ia/comparer-ecu', [AiDiagnosticController::class, 'compareEcu'])
        ->middleware('throttle:15,1')
        ->name('tenant.ai_diagnostic.compare_ecu');
    Route::post('/assistant-ia/diagnostic-acoustique', [AiDiagnosticController::class, 'acousticDiagnostic'])
        ->middleware('throttle:15,1')
        ->name('tenant.ai_diagnostic.acoustic');
    Route::post('/assistant-ia/messages/{messageId}/creer-devis', [AiDiagnosticController::class, 'createQuoteFromMessage'])
        ->name('tenant.ai_diagnostic.create_quote');
    Route::post('/assistant-ia/messages/{messageId}/appliquer-or', [AiDiagnosticController::class, 'applyToRepairOrder'])
        ->name('tenant.ai_diagnostic.apply_ro');
    Route::post('/assistant-ia/messages/{messageId}/expliquer-client', [AiDiagnosticController::class, 'explainForClient'])
        ->name('tenant.ai_diagnostic.explain_client');
    Route::post('/assistant-ia/messages/{messageId}/envoyer-whatsapp', [AiDiagnosticController::class, 'sendClientWhatsapp'])
        ->name('tenant.ai_diagnostic.send_whatsapp');
    Route::post('/assistant-ia/messages/{messageId}/feedback', [AiDiagnosticController::class, 'submitFeedback'])
        ->name('tenant.ai_diagnostic.feedback');
    Route::delete('/assistant-ia/{conversationId}', [AiDiagnosticController::class, 'destroy'])->name('tenant.ai_diagnostic.destroy');

    // Mode Hors Ligne & Synchronisation (PWA / IndexedDB)
    Route::get('/offline/ping', [OfflineSyncController::class, 'ping'])
        ->middleware('throttle:60,1')
        ->name('tenant.offline.ping');
    Route::get('/offline/bootstrap', [OfflineSyncController::class, 'bootstrap'])
        ->middleware('throttle:15,1')
        ->name('tenant.offline.bootstrap');
    Route::post('/offline/sync', [OfflineSyncController::class, 'sync'])
        ->middleware('throttle:60,1')
        ->name('tenant.offline.sync');

    // Clients & Véhicules
    Route::get('/clients', [CustomerController::class, 'index'])
        ->middleware('throttle:search')
        ->name('tenant.customers.index');
    Route::get('/clients/lookup-global', [CustomerController::class, 'lookupGlobal'])
        ->middleware('throttle:search')
        ->name('tenant.customers.lookup_global');
    Route::post('/clients', [CustomerController::class, 'store'])->name('tenant.customers.store');
    Route::put('/clients/{customer}', [CustomerController::class, 'update'])->name('tenant.customers.update');
    Route::post('/clients/{customer}/relancer-whatsapp', [CustomerController::class, 'remindWhatsapp'])->name('tenant.customers.remind_whatsapp');
    Route::get('/clients/{customer}', [CustomerController::class, 'show'])->name('tenant.customers.show');
    Route::post('/clients/{customer}/relancer', [CustomerController::class, 'remind'])->name('tenant.customers.remind');

    Route::get('/vehicules', [VehicleController::class, 'index'])
        ->middleware('throttle:search')
        ->name('tenant.vehicles.index');
    Route::post('/vehicules', [VehicleController::class, 'store'])->name('tenant.vehicles.store');
    Route::post('/vehicules/ocr', [VehicleController::class, 'ocrScan'])
        ->middleware('throttle:15,1')
        ->name('tenant.vehicles.ocr');
    Route::put('/vehicules/{vehicle}', [VehicleController::class, 'update'])->name('tenant.vehicles.update');
    Route::get('/vehicules/{vehicle}', [VehicleController::class, 'show'])->name('tenant.vehicles.show');
    Route::get('/vehicules/{vehicle}/etiquette-qr', [VehicleController::class, 'qrLabel'])->name('tenant.vehicles.qr_label');

    // Rendez-vous & File sans rendez-vous
    Route::get('/rendez-vous', [AppointmentController::class, 'index'])->name('tenant.appointments.index');
    Route::post('/rendez-vous', [AppointmentController::class, 'store'])->name('tenant.appointments.store');
    Route::patch('/rendez-vous/{appointment}/statut', [AppointmentController::class, 'updateStatus'])->name('tenant.appointments.status');
    Route::post('/rendez-vous/{appointment}/rappel-whatsapp', [AppointmentController::class, 'sendWhatsappReminder'])->name('tenant.appointments.whatsapp_reminder');
    Route::post('/rendez-vous/liens', [AppointmentController::class, 'generateLink'])->name('tenant.appointments.links.store');
    Route::post('/rendez-vous/liens/{appointmentLink}/valider', [AppointmentController::class, 'confirmLinkSubmission'])->name('tenant.appointments.links.confirm');
    Route::post('/rendez-vous/liens/{appointmentLink}/annuler', [AppointmentController::class, 'cancelLink'])->name('tenant.appointments.links.cancel');

    // Modules réservés à l'espace Garage / Atelier
    Route::middleware('tenant.type:garage')->group(function () {
        // Réception & Véhicules de courtoisie
        Route::get('/receptions', [VehicleReceptionController::class, 'index'])->name('tenant.receptions.index');
        Route::get('/receptions/nouvelle', [VehicleReceptionController::class, 'create'])->name('tenant.receptions.create');
        Route::post('/receptions', [VehicleReceptionController::class, 'store'])->name('tenant.receptions.store');
        Route::put('/receptions/{reception}', [VehicleReceptionController::class, 'update'])->name('tenant.receptions.update');
        Route::post('/receptions/{reception}/retour-courtoisie', [VehicleReceptionController::class, 'returnCourtesy'])->name('tenant.receptions.return_courtesy');
        Route::post('/receptions/vehicules-courtoisie', [VehicleReceptionController::class, 'storeCourtesyVehicle'])->name('tenant.receptions.courtesy.store');
        Route::patch('/receptions/vehicules-courtoisie/{courtesyVehicle}/statut', [VehicleReceptionController::class, 'updateCourtesyStatus'])->name('tenant.receptions.courtesy.status');

        // Ordres de réparation, diagnostic & planification
        Route::get('/ordres-reparation', [RepairOrderController::class, 'index'])->name('tenant.repair_orders.index');
        Route::post('/ordres-reparation', [RepairOrderController::class, 'store'])->name('tenant.repair_orders.store');
        Route::post('/ordres-reparation/{repairOrder}/diagnostic', [RepairOrderController::class, 'saveDiagnostic'])->name('tenant.repair_orders.diagnostic');
        Route::post('/ordres-reparation/{repairOrder}/taches', [RepairOrderController::class, 'addTask'])->name('tenant.repair_orders.tasks.store');

        // Devis multi-versions, validation client & PDF
        Route::get('/devis', [QuoteController::class, 'index'])->name('tenant.quotes.index');
        Route::post('/devis', [QuoteController::class, 'store'])->name('tenant.quotes.store');
        Route::post('/devis/{quote}/valider', [QuoteController::class, 'accept'])->name('tenant.quotes.accept');
        Route::put('/devis/{quote}/reajuster', [QuoteController::class, 'revise'])->name('tenant.quotes.revise');
        Route::post('/devis/{quote}/nouvelle-version', [QuoteController::class, 'newVersion'])->name('tenant.quotes.new_version');
        Route::post('/devis/{quote}/rappeler', [QuoteController::class, 'remind'])->name('tenant.quotes.remind');
        Route::get('/devis/{quote}/pdf', [QuoteController::class, 'pdf'])
            ->middleware('throttle:pdf')
            ->name('tenant.quotes.pdf');

        // Stock, fournisseurs & approvisionnement
        Route::get('/stock', [InventoryController::class, 'index'])->name('tenant.inventory.index');
        Route::post('/stock/pieces', [InventoryController::class, 'storePart'])->name('tenant.inventory.parts.store');
        Route::post('/stock/fournisseurs', [InventoryController::class, 'storeSupplier'])->name('tenant.inventory.suppliers.store');
        Route::post('/stock/pieces/{part}/reserver', [InventoryController::class, 'reserve'])->name('tenant.inventory.parts.reserve');
        Route::post('/stock/pieces/{part}/sortie', [InventoryController::class, 'issue'])->name('tenant.inventory.parts.issue');
        Route::post('/stock/pieces/{part}/transfert', [InventoryController::class, 'transfer'])->name('tenant.inventory.parts.transfer');

        Route::get('/approvisionnement', [PurchaseOrderController::class, 'index'])->name('tenant.purchase_orders.index');
        Route::post('/approvisionnement', [PurchaseOrderController::class, 'store'])->name('tenant.purchase_orders.store');
        Route::post('/approvisionnement/{purchaseOrder}/relancer', [PurchaseOrderController::class, 'remind'])->name('tenant.purchase_orders.remind');
        Route::post('/approvisionnement/{purchaseOrder}/reception', [PurchaseOrderController::class, 'receive'])->name('tenant.purchase_orders.receive');

        // Atelier, pointage & contrôle qualité
        Route::get('/atelier/mes-or', [WorkshopController::class, 'myOrders'])->name('tenant.workshop.my_orders');
        Route::patch('/atelier/or/{repairOrder}/statut', [WorkshopController::class, 'updateStatus'])->name('tenant.workshop.status');
        Route::post('/atelier/taches/{task}/pointage', [WorkshopController::class, 'logTime'])->name('tenant.workshop.log_time');
        Route::post('/atelier/taches/{task}/chrono/demarrer', [WorkshopController::class, 'startTimer'])->name('tenant.workshop.timer_start');
        Route::post('/atelier/taches/{task}/chrono/pause', [WorkshopController::class, 'pauseTimer'])->name('tenant.workshop.timer_pause');
        Route::post('/atelier/or/{repairOrder}/controle-qualite', [WorkshopController::class, 'submitQualityCheck'])->name('tenant.workshop.quality_check');

        // Inspection Visuelle Numérique (DVI) & Photos WhatsApp
        Route::get('/inspections', [InspectionController::class, 'index'])->name('tenant.inspections.index');
        Route::post('/inspections', [InspectionController::class, 'store'])->name('tenant.inspections.store');
        Route::post('/inspections/{inspection}/whatsapp', [InspectionController::class, 'sendWhatsapp'])->name('tenant.inspections.whatsapp');

        // Sous-traitance & Dépannage / Remorquage
        Route::middleware('tenant.permission:repair_orders.manage|receptions.manage')->group(function () {
            Route::get('/sous-traitance', [SubcontractController::class, 'index'])->name('tenant.subcontracting.index');
            Route::post('/sous-traitance', [SubcontractController::class, 'store'])->name('tenant.subcontracting.store');
            Route::patch('/sous-traitance/{intervention}/statut', [SubcontractController::class, 'updateStatus'])->name('tenant.subcontracting.status');
            Route::delete('/sous-traitance/{intervention}', [SubcontractController::class, 'destroy'])->name('tenant.subcontracting.destroy');
        });

        // Outillage Atelier & Consommables en Vrac
        Route::middleware('tenant.permission:stock.view|stock.manage|repair_orders.manage|receptions.manage')->group(function () {
            Route::get('/outillage-vrac', [ToolsAndBulkController::class, 'index'])->name('tenant.tools_bulk.index');
            Route::post('/outillage-vrac/outils', [ToolsAndBulkController::class, 'storeTool'])->name('tenant.tools_bulk.tools.store');
            Route::post('/outillage-vrac/outils/{tool}/emprunter', [ToolsAndBulkController::class, 'borrowTool'])->name('tenant.tools_bulk.tools.borrow');
            Route::post('/outillage-vrac/outils/{tool}/rendre', [ToolsAndBulkController::class, 'returnTool'])->name('tenant.tools_bulk.tools.return');
            Route::delete('/outillage-vrac/outils/{tool}', [ToolsAndBulkController::class, 'destroyTool'])->name('tenant.tools_bulk.tools.destroy');
            Route::post('/outillage-vrac/vrac', [ToolsAndBulkController::class, 'storeBulk'])->name('tenant.tools_bulk.bulk.store');
            Route::post('/outillage-vrac/vrac/{consumable}/mouvement', [ToolsAndBulkController::class, 'logBulkMovement'])->name('tenant.tools_bulk.bulk.movement');
        });
    });

    // Fichiers privés signés (photos, signatures)
    Route::get('/fichiers-securises/{path}', [SecureFileController::class, 'show'])->name('tenant.files.show');

    // Planning Visuel (Garage & Centre Technique)
    Route::middleware(['tenant.type:garage,centre_technique', 'tenant.permission:planning.manage|appointments.view|receptions.manage'])->group(function () {
        Route::get('/planning-atelier', [WorkshopPlanningController::class, 'index'])->name('tenant.planning.index');
        Route::patch('/planning-atelier/rdv/{appointment}', [WorkshopPlanningController::class, 'assignAppointment'])->name('tenant.planning.assign_appointment');
        Route::patch('/planning-atelier/or/{repairOrder}', [WorkshopPlanningController::class, 'assignRepairOrder'])->name('tenant.planning.assign_ro');
    });

    // Rappels Périodiques (Vidange, Visite Technique, Assurance, Courroie/Plaquettes — tous espaces)
    Route::middleware('tenant.permission:clients.view|vehicles.view|receptions.manage')->group(function () {
        Route::get('/rappels-entretien', [MaintenanceReminderController::class, 'index'])->name('tenant.maintenance_reminders.index');
        Route::post('/rappels-entretien/lancer-auto', [MaintenanceReminderController::class, 'runPredictiveReminders'])->name('tenant.maintenance_reminders.run_auto');
        Route::patch('/rappels-entretien/{vehicle}', [MaintenanceReminderController::class, 'updateDates'])->name('tenant.maintenance_reminders.update');
        Route::post('/rappels-entretien/{vehicle}/whatsapp', [MaintenanceReminderController::class, 'sendWhatsapp'])->name('tenant.maintenance_reminders.whatsapp');
    });

    // Flottes Entreprises (B2B — Garage & Assurance)
    Route::middleware(['tenant.type:garage,assurance', 'tenant.permission:clients.manage|invoices.manage'])->group(function () {
        Route::get('/flottes', [FleetController::class, 'index'])->name('tenant.fleets.index');
        Route::post('/flottes/{customer}/parametres', [FleetController::class, 'updateSettings'])->name('tenant.fleets.settings');
        Route::post('/flottes/{customer}/facture-mensuelle', [FleetController::class, 'generateMonthlyInvoice'])->name('tenant.fleets.monthly_invoice');
        Route::patch('/flottes/vehicules/{vehicle}/seuil-cout', [FleetController::class, 'updateVehicleThreshold'])->name('tenant.fleets.vehicle_threshold');
    });

    // 7. Centre de Contrôle Technique (PV Visite Technique & QR)
    Route::middleware(['tenant.type:centre_technique', 'tenant.permission:vehicles.manage|receptions.manage|quality_control.manage'])->group(function () {
        Route::get('/visite-technique', [TechnicalInspectionController::class, 'index'])->name('tenant.technical_inspection.index');
        Route::post('/visite-technique', [TechnicalInspectionController::class, 'store'])->name('tenant.technical_inspection.store');
        Route::put('/visite-technique/{technicalInspection}', [TechnicalInspectionController::class, 'update'])->name('tenant.technical_inspection.update');
        Route::get('/visite-technique/{technicalInspection}/pdf', [TechnicalInspectionController::class, 'pdf'])
            ->middleware('throttle:pdf')
            ->name('tenant.technical_inspection.pdf');
        Route::post('/visite-technique/{technicalInspection}/whatsapp', [TechnicalInspectionController::class, 'sendWhatsapp'])->name('tenant.technical_inspection.whatsapp');
    });

    // 8. Assurances Automobile (Polices, Sinistres & Prises en charge — passerelle Assurance <-> Garage)
    Route::middleware(['tenant.type:assurance,garage', 'tenant.permission:clients.manage|quotes.manage|invoices.manage'])->group(function () {
        Route::get('/assurances', [InsuranceController::class, 'index'])->name('tenant.insurance.index');
        Route::post('/assurances/polices', [InsuranceController::class, 'storePolicy'])->name('tenant.insurance.policies.store');
        Route::put('/assurances/polices/{policy}', [InsuranceController::class, 'updatePolicy'])->name('tenant.insurance.policies.update');
        Route::delete('/assurances/polices/{policy}', [InsuranceController::class, 'destroyPolicy'])->name('tenant.insurance.policies.destroy');
        Route::get('/assurances/polices/{policy}/pdf', [InsuranceController::class, 'policyPdf'])
            ->middleware('throttle:pdf')
            ->name('tenant.insurance.policies.pdf');
        Route::post('/assurances/sinistres', [InsuranceController::class, 'storeClaim'])->name('tenant.insurance.claims.store');
        Route::post('/assurances/sinistres/{claimId}/approuver', [InsuranceController::class, 'approveClaim'])->name('tenant.insurance.claims.approve');
        Route::patch('/assurances/sinistres/{claimId}/statut', [InsuranceController::class, 'updateClaimStatus'])->name('tenant.insurance.claims.status');
        Route::get('/assurances/sinistres/{claimId}/pdf', [InsuranceController::class, 'claimPdf'])
            ->middleware('throttle:pdf')
            ->name('tenant.insurance.claims.pdf');
    });

    // Facturation, avoirs & PDF
    Route::get('/factures', [InvoiceController::class, 'index'])->name('tenant.invoices.index');
    Route::post('/factures/relancer-echues', [InvoiceController::class, 'runOverdueReminders'])->name('tenant.invoices.run_overdue_reminders');
    Route::post('/ordres-reparation/{repairOrder}/facturer', [InvoiceController::class, 'storeFromRepairOrder'])->name('tenant.invoices.store_from_ro');
    Route::post('/factures/{invoice}/avoir', [InvoiceController::class, 'createCreditNote'])->name('tenant.invoices.credit_note');
    Route::post('/factures/{invoice}/relancer', [InvoiceController::class, 'remind'])->name('tenant.invoices.remind');
    Route::post('/factures/{invoice}/echeancier', [InvoiceController::class, 'saveInstallmentPlan'])->name('tenant.invoices.installment_plan');
    Route::get('/factures/{invoice}/pdf', [InvoiceController::class, 'pdf'])
        ->middleware('throttle:pdf')
        ->name('tenant.invoices.pdf');

    // Sortie du véhicule
    Route::post('/ordres-reparation/{repairOrder}/sortie', [VehicleExitController::class, 'store'])->name('tenant.vehicle_exits.store');

    // Paiements factures, reçus PDF & clôture de caisse
    Route::post('/factures/{invoice}/payer', [PaymentController::class, 'payInvoice'])->name('tenant.invoices.pay');
    Route::get('/paiements/{payment}/recu-pdf', [PaymentController::class, 'receiptPdf'])
        ->middleware('throttle:pdf')
        ->name('tenant.payments.receipt_pdf');
    Route::get('/paiements/{payment}/imprimer', [PaymentController::class, 'printReceipt'])
        ->name('tenant.payments.receipt_print');
    Route::get('/export-excel/{type}', [ExportController::class, 'excel'])
        ->middleware('tenant.permission:reports.view|invoices.view|clients.view')
        ->name('tenant.export.excel');
    Route::post('/caisse/ouvrir', [PaymentController::class, 'openCashRegister'])->name('tenant.cash_register.open');
    Route::post('/caisse/cloture', [PaymentController::class, 'closeCashRegister'])->name('tenant.cash_register.close');

    // Comptabilité SYSCOHADA révisée
    Route::middleware('tenant.permission:accounting.view|accounting.manage')->group(function () {
        Route::get('/comptabilite', [AccountingController::class, 'index'])->name('tenant.accounting.index');
        Route::post('/comptabilite/ecritures', [AccountingController::class, 'storeEntry'])->name('tenant.accounting.store_entry');
        Route::post('/comptabilite/ecritures/{journalEntry}/contre-passer', [AccountingController::class, 'reverse'])->name('tenant.accounting.reverse');
        Route::post('/comptabilite/exercices/{fiscalYear}/cloturer', [AccountingController::class, 'closeFiscalYear'])->name('tenant.accounting.close_fy');
        Route::get('/comptabilite/balance-pdf', [AccountingController::class, 'balancePdf'])
            ->middleware('throttle:pdf')
            ->name('tenant.accounting.balance_pdf');
    });

    // RH — Pointage, Avances/Primes & Bulletins de Paie
    Route::middleware('tenant.permission:hr.manage')->group(function () {
        Route::get('/rh', [HrController::class, 'index'])->name('tenant.hr.index');
        Route::post('/rh/pointages/{attendance}/arrivee', [HrController::class, 'checkIn'])->name('tenant.hr.attendance.checkin');
        Route::post('/rh/pointages/{attendance}/depart', [HrController::class, 'checkOut'])->name('tenant.hr.attendance.checkout');
        Route::patch('/rh/pointages/{attendance}/statut', [HrController::class, 'updateStatus'])->name('tenant.hr.attendance.status');
        Route::post('/rh/ajustements', [HrController::class, 'storeAdjustment'])->name('tenant.hr.adjustments.store');
        Route::delete('/rh/ajustements/{adjustment}', [HrController::class, 'destroyAdjustment'])->name('tenant.hr.adjustments.destroy');
        Route::post('/rh/bulletins/generer', [HrController::class, 'generatePayslips'])->name('tenant.hr.payslips.generate');
        Route::post('/rh/bulletins/{payslip}/payer', [HrController::class, 'payPayslip'])->name('tenant.hr.payslips.pay');
        Route::get('/rh/bulletins/{payslip}/pdf', [HrController::class, 'payslipPdf'])
            ->middleware('throttle:pdf')
            ->name('tenant.hr.payslips.pdf');
        Route::post('/rh/bulletins/{payslip}/whatsapp', [HrController::class, 'sendPayslipWhatsapp'])->name('tenant.hr.payslips.whatsapp');
    });

    Route::get('/apres-vente', [AfterSalesController::class, 'index'])->name('tenant.after_sales.index');
    Route::post('/apres-vente/enquetes-j1', [AfterSalesController::class, 'dispatchReviews'])->name('tenant.after_sales.dispatch_reviews');
    Route::post('/apres-vente/dossiers', [AfterSalesController::class, 'storeCase'])->name('tenant.after_sales.cases.store');
    Route::post('/apres-vente/fidelite', [AfterSalesController::class, 'awardLoyaltyPoints'])->name('tenant.after_sales.loyalty');

    Route::middleware('tenant.permission:reports.view')->group(function () {
        Route::get('/rapports', [ReportController::class, 'index'])->name('tenant.reports.index');
        Route::post('/rapports/depenses', [ReportController::class, 'storeExpense'])->name('tenant.reports.expenses.store');
    });

    // Messagerie & Notifications internes / clients + Réseau Pro Inter-Établissements (B2B)
    Route::middleware('tenant.permission:clients.view|settings.manage')->group(function () {
        Route::get('/messagerie', [MessageController::class, 'index'])->name('tenant.messages.index');
        Route::post('/messagerie', [MessageController::class, 'store'])->name('tenant.messages.store');
        Route::post('/messagerie/{message}/repondre', [MessageController::class, 'reply'])->name('tenant.messages.reply');
        Route::post('/messagerie/{message}/lu', [MessageController::class, 'markRead'])->name('tenant.messages.read');

        Route::get('/reseau-pro/etat', [B2bNetworkController::class, 'state'])->name('tenant.b2b_network.state');
        Route::post('/reseau-pro/conversations', [B2bNetworkController::class, 'startConversation'])
            ->middleware('throttle:30,1')
            ->name('tenant.b2b_network.start');
        Route::post('/reseau-pro/conversations/{conversation}/messages', [B2bNetworkController::class, 'sendMessage'])
            ->middleware('throttle:40,1')
            ->name('tenant.b2b_network.send');
        Route::get('/reseau-pro/messages/{message}/piece-jointe/{index}', [B2bNetworkController::class, 'downloadAttachment'])
            ->name('tenant.b2b_network.attachment');
        Route::get('/reseau-pro/messages/{message}/apercu/{index}', [B2bNetworkController::class, 'previewAttachment'])
            ->name('tenant.b2b_network.preview');
        Route::get('/reseau-pro/messages/{message}/audio', [B2bNetworkController::class, 'streamAudio'])
            ->name('tenant.b2b_network.audio');
        Route::patch('/reseau-pro/visibilite', [B2bNetworkController::class, 'toggleVisibility'])
            ->name('tenant.b2b_network.visibility');
        Route::post('/reseau-pro/demandes', [B2bNetworkController::class, 'storeSosRequest'])
            ->middleware('throttle:15,1')
            ->name('tenant.b2b_network.sos.store');
        Route::patch('/reseau-pro/demandes/{sosRequest}/resoudre', [B2bNetworkController::class, 'resolveSosRequest'])
            ->name('tenant.b2b_network.sos.resolve');
        Route::post('/reseau-pro/demandes/{sosRequest}/repondre', [B2bNetworkController::class, 'replyToSosRequest'])
            ->middleware('throttle:30,1')
            ->name('tenant.b2b_network.sos.reply');
        Route::post('/reseau-pro/bloquer/{targetTenant}', [B2bNetworkController::class, 'blockTenant'])
            ->name('tenant.b2b_network.block');
        Route::delete('/reseau-pro/bloquer/{targetTenant}', [B2bNetworkController::class, 'unblockTenant'])
            ->name('tenant.b2b_network.unblock');
    });

    // Gestion Marketplace côté Garage (Catégories, Sous-catégories, Produits, Commandes)
    Route::prefix('marketplace-gestion')->name('tenant.marketplace.')->middleware('tenant.permission:stock.manage|settings.manage')->group(function () {
        Route::get('/categories', [MarketplaceManagementController::class, 'categories'])->name('categories');
        Route::post('/categories', [MarketplaceManagementController::class, 'storeCategory'])->name('categories.store');
        Route::delete('/categories/{category}', [MarketplaceManagementController::class, 'deleteCategory'])->name('categories.delete');
        Route::post('/sous-categories', [MarketplaceManagementController::class, 'storeSubCategory'])->name('subcategories.store');
        Route::delete('/sous-categories/{subCategory}', [MarketplaceManagementController::class, 'deleteSubCategory'])->name('subcategories.delete');

        Route::get('/produits', [MarketplaceManagementController::class, 'products'])->name('products');
        Route::post('/produits', [MarketplaceManagementController::class, 'storeProduct'])->name('products.store');
        Route::post('/produits/{product}/toggle', [MarketplaceManagementController::class, 'toggleProduct'])->name('products.toggle');
        Route::delete('/produits/{product}', [MarketplaceManagementController::class, 'deleteProduct'])->name('products.delete');

        Route::get('/commandes', [MarketplaceManagementController::class, 'orders'])->name('orders');
        Route::patch('/commandes/{orderItem}/statut', [MarketplaceManagementController::class, 'updateOrderStatus'])->name('orders.status');
    });
});

// Téléchargement sécurisé des pièces jointes (URL signée pour e-mails clients ou session authentifiée)
Route::get('/messages/pieces-jointes/{message}/{index}', [MessageController::class, 'downloadAttachment'])
    ->name('messages.attachment.download');

// Abonnement & retour SaSPay (accessibles même en mode lecture seule pour permettre le renouvellement)
Route::middleware('tenant.auth')->group(function () {
    Route::get('/abonnement', [PaymentController::class, 'subscriptionPage'])->name('tenant.subscription.index');
    Route::post('/abonnement/payer', [PaymentController::class, 'paySubscription'])->name('tenant.subscription.pay');
    Route::get('/abonnement/export/pdf', [PaymentController::class, 'exportSubscriptionsPdf'])->name('tenant.subscription.export.pdf');
    Route::get('/abonnement/export/excel', [PaymentController::class, 'exportSubscriptionsExcel'])->name('tenant.subscription.export.excel');
    Route::middleware('tenant.permission:settings.manage')->group(function () {
        Route::get('/affiliation', [AffiliationController::class, 'index'])->name('tenant.affiliation.index');
        Route::post('/affiliation', [AffiliationController::class, 'store'])->middleware('tenant.writable')->name('tenant.affiliation.store');
        Route::post('/affiliation/payer-abonnement', [AffiliationController::class, 'paySubscription'])->name('tenant.affiliation.pay');
    });
    Route::get('/paiements/{payment}/retour', [PaymentController::class, 'returnCallback'])->name('tenant.payments.return');
});

// Webhook SaSPay (sans CSRF, rate-limité, signature vérifiée)
Route::post('/webhooks/saspay', [SaspayWebhookController::class, 'handle'])
    ->middleware('throttle:webhook')
    ->name('webhooks.saspay');

// Back-office Super-Admin (guard dédié 'admin', interface Livewire)
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:admin')->group(function () {
        Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AdminAuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('login.post');
    });

    Route::middleware('auth:admin')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::get('/', fn () => view('admin.dashboard', ['section' => 'overview']))->name('dashboard');
        Route::get('/garages', fn () => view('admin.dashboard', ['section' => 'tenants']))->name('tenants');
        Route::get('/ia-diagnostics', fn () => view('admin.dashboard', ['section' => 'ai_diagnostics']))->name('ai_diagnostics');
        Route::get('/messagerie', fn () => view('admin.dashboard', ['section' => 'messages']))->name('messages');
        Route::get('/affiliation', fn () => view('admin.dashboard', ['section' => 'affiliation']))->name('affiliation');
        Route::get('/categories', fn () => view('admin.dashboard', ['section' => 'categories']))->name('categories');
        Route::get('/bannieres', fn () => view('admin.dashboard', ['section' => 'banners']))->name('banners');
        Route::get('/temoignages', fn () => view('admin.dashboard', ['section' => 'testimonials']))->name('testimonials');
        Route::get('/partenaires', fn () => view('admin.dashboard', ['section' => 'partners']))->name('partners');
        Route::get('/pays', fn () => view('admin.dashboard', ['section' => 'countries']))->name('countries');
        Route::get('/conditions-utilisation', fn () => view('admin.dashboard', ['section' => 'terms']))->name('terms');
        Route::get('/politique-confidentialite', fn () => view('admin.dashboard', ['section' => 'privacy']))->name('privacy');
        Route::get('/securite', fn () => view('admin.dashboard', ['section' => 'security']))->name('security');
        Route::get('/administrateurs', fn () => view('admin.dashboard', ['section' => 'admins']))->name('admins');
        Route::get('/parametres', fn () => view('admin.dashboard', ['section' => 'settings']))->name('settings');
        Route::get('/profil', fn () => view('admin.dashboard', ['section' => 'profile']))->name('profile');
    });
});
