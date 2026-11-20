<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Auth\TenantAuthController;
use App\Http\Controllers\Auth\TenantRegistrationController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\PublicAppointmentController;
use App\Http\Controllers\PublicTrackingController;
use App\Http\Controllers\Tenant\AccountingController;
use App\Http\Controllers\Tenant\AffiliationController;
use App\Http\Controllers\Tenant\AfterSalesController;
use App\Http\Controllers\Tenant\AgencyController;
use App\Http\Controllers\Tenant\AppointmentController;
use App\Http\Controllers\Tenant\CustomerController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\ExportController;
use App\Http\Controllers\Tenant\HrController;
use App\Http\Controllers\Tenant\InventoryController;
use App\Http\Controllers\Tenant\InvoiceController;
use App\Http\Controllers\Tenant\MarketplaceManagementController;
use App\Http\Controllers\Tenant\MessageController;
use App\Http\Controllers\Tenant\PaymentController;
use App\Http\Controllers\Tenant\ProfessionalController;
use App\Http\Controllers\Tenant\ProfileController;
use App\Http\Controllers\Tenant\PurchaseOrderController;
use App\Http\Controllers\Tenant\QuoteController;
use App\Http\Controllers\Tenant\RepairOrderController;
use App\Http\Controllers\Tenant\ReportController;
use App\Http\Controllers\Tenant\SecureFileController;
use App\Http\Controllers\Tenant\SpaceSettingsController;
use App\Http\Controllers\Tenant\VehicleController;
use App\Http\Controllers\Tenant\VehicleExitController;
use App\Http\Controllers\Tenant\VehicleReceptionController;
use App\Http\Controllers\Tenant\WorkshopController;
use App\Http\Controllers\Webhooks\SaspayWebhookController;
use App\Models\PlatformSetting;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Page d'accueil marketing
Route::get('/', function () {
    return Inertia::render('Welcome', [
        'appName' => config('app.name', 'GaragePro'),
        'trialDays' => PlatformSetting::trialDays(),
        'monthlyPrice' => PlatformSetting::subscriptionPrice(),
        'baseDomain' => config('garagepro.tenant_base_domain', 'mboacar.com'),
        'testimonials' => Testimonial::published()->latest()->take(10)->get(),
    ]);
})->name('home');

// Page Contact
Route::get('/contact', [ContactController::class, 'show'])->name('contact.show');
Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('contact.store');

// Marketplace Public (Pièces Automobiles)
Route::get('/marketplace', [MarketplaceController::class, 'index'])->name('marketplace.index');
Route::get('/marketplace/bons-plans', [MarketplaceController::class, 'bestSellers'])->name('marketplace.best_sellers');
Route::get('/marketplace/api/search', [MarketplaceController::class, 'apiSearch'])->name('marketplace.api.search');
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
Route::post('/commander', [MarketplaceController::class, 'placeOrder'])->name('marketplace.order.place');

Route::get('/conditions-utilisation', function () {
    return Inertia::render('Legal/Show', [
        'title' => 'Conditions d\'utilisation',
        'slug' => 'terms',
        'content' => \App\Models\PlatformSetting::termsOfUse(),
    ]);
})->name('legal.terms');

Route::get('/politique-confidentialite', function () {
    return Inertia::render('Legal/Show', [
        'title' => 'Politique de confidentialité',
        'slug' => 'privacy',
        'content' => \App\Models\PlatformSetting::privacyPolicy(),
    ]);
})->name('legal.privacy');

// Mode Sandbox Démo sans inscription
Route::get('/demo', [DemoController::class, 'enter'])->name('demo.enter');
Route::post('/demo/reinitialiser', [DemoController::class, 'reset'])->name('demo.reset');

// Carnet d'entretien numérique & Suivi client en direct
Route::get('/carnet/{token}', [PublicTrackingController::class, 'vehiclePassport'])->name('public.vehicle.passport');
Route::get('/suivi/{token}', [PublicTrackingController::class, 'repairOrderTracking'])->name('public.repair_order.tracking');
Route::post('/suivi/{token}/devis/{quoteId}/repondre', [PublicTrackingController::class, 'respondQuote'])->name('public.repair_order.quote_respond');
Route::get('/rdv/{token}', [PublicAppointmentController::class, 'show'])->name('public.appointment.show');
Route::post('/rdv/{token}', [PublicAppointmentController::class, 'submit'])
    ->middleware('throttle:10,1')
    ->name('public.appointment.submit');

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
Route::middleware('guest:web')->group(function () {
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
Route::middleware('guest:web')->group(function () {
    Route::get('/connexion', [TenantAuthController::class, 'showLogin'])->name('tenant.login');
    Route::post('/connexion', [TenantAuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('tenant.login.post');
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
    });

    // Clients & Véhicules
    Route::get('/clients', [CustomerController::class, 'index'])
        ->middleware('throttle:search')
        ->name('tenant.customers.index');
    Route::post('/clients', [CustomerController::class, 'store'])->name('tenant.customers.store');
    Route::post('/clients/{customer}/relancer-whatsapp', [CustomerController::class, 'remindWhatsapp'])->name('tenant.customers.remind_whatsapp');
    Route::get('/clients/{customer}', [CustomerController::class, 'show'])->name('tenant.customers.show');
    Route::post('/clients/{customer}/relancer', [CustomerController::class, 'remind'])->name('tenant.customers.remind');

    Route::get('/vehicules', [VehicleController::class, 'index'])
        ->middleware('throttle:search')
        ->name('tenant.vehicles.index');
    Route::post('/vehicules', [VehicleController::class, 'store'])->name('tenant.vehicles.store');
    Route::get('/vehicules/{vehicle}', [VehicleController::class, 'show'])->name('tenant.vehicles.show');

    // Rendez-vous & File sans rendez-vous
    Route::get('/rendez-vous', [AppointmentController::class, 'index'])->name('tenant.appointments.index');
    Route::post('/rendez-vous', [AppointmentController::class, 'store'])->name('tenant.appointments.store');
    Route::patch('/rendez-vous/{appointment}/statut', [AppointmentController::class, 'updateStatus'])->name('tenant.appointments.status');
    Route::post('/rendez-vous/{appointment}/rappel-whatsapp', [AppointmentController::class, 'sendWhatsappReminder'])->name('tenant.appointments.whatsapp_reminder');
    Route::post('/rendez-vous/liens', [AppointmentController::class, 'generateLink'])->name('tenant.appointments.links.store');
    Route::post('/rendez-vous/liens/{appointmentLink}/valider', [AppointmentController::class, 'confirmLinkSubmission'])->name('tenant.appointments.links.confirm');
    Route::post('/rendez-vous/liens/{appointmentLink}/annuler', [AppointmentController::class, 'cancelLink'])->name('tenant.appointments.links.cancel');

    // Réception & Véhicules de courtoisie
    Route::get('/receptions', [VehicleReceptionController::class, 'index'])->name('tenant.receptions.index');
    Route::post('/receptions', [VehicleReceptionController::class, 'store'])->name('tenant.receptions.store');
    Route::post('/receptions/{reception}/retour-courtoisie', [VehicleReceptionController::class, 'returnCourtesy'])->name('tenant.receptions.return_courtesy');

    // Fichiers privés signés (photos, signatures)
    Route::get('/fichiers-securises/{path}', [SecureFileController::class, 'show'])->name('tenant.files.show');

    // Ordres de réparation, diagnostic & planification
    Route::get('/ordres-reparation', [RepairOrderController::class, 'index'])->name('tenant.repair_orders.index');
    Route::post('/ordres-reparation', [RepairOrderController::class, 'store'])->name('tenant.repair_orders.store');
    Route::post('/ordres-reparation/{repairOrder}/diagnostic', [RepairOrderController::class, 'saveDiagnostic'])->name('tenant.repair_orders.diagnostic');
    Route::post('/ordres-reparation/{repairOrder}/taches', [RepairOrderController::class, 'addTask'])->name('tenant.repair_orders.tasks.store');

    // Devis multi-versions, validation client & PDF
    Route::get('/devis', [QuoteController::class, 'index'])->name('tenant.quotes.index');
    Route::post('/devis', [QuoteController::class, 'store'])->name('tenant.quotes.store');
    Route::post('/devis/{quote}/valider', [QuoteController::class, 'accept'])->name('tenant.quotes.accept');
    Route::post('/devis/{quote}/nouvelle-version', [QuoteController::class, 'newVersion'])->name('tenant.quotes.new_version');
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
    Route::post('/atelier/or/{repairOrder}/controle-qualite', [WorkshopController::class, 'submitQualityCheck'])->name('tenant.workshop.quality_check');

    // Facturation, avoirs & PDF
    Route::get('/factures', [InvoiceController::class, 'index'])->name('tenant.invoices.index');
    Route::post('/ordres-reparation/{repairOrder}/facturer', [InvoiceController::class, 'storeFromRepairOrder'])->name('tenant.invoices.store_from_ro');
    Route::post('/factures/{invoice}/avoir', [InvoiceController::class, 'createCreditNote'])->name('tenant.invoices.credit_note');
    Route::post('/factures/{invoice}/relancer', [InvoiceController::class, 'remind'])->name('tenant.invoices.remind');
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
        ->name('tenant.export.excel');
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

    // RH — Pointage & Paie (les professionnels sont créés depuis /professionnels)
    Route::middleware('tenant.permission:hr.manage')->group(function () {
        Route::get('/rh', [HrController::class, 'index'])->name('tenant.hr.index');
        Route::post('/rh/pointages/{attendance}/arrivee', [HrController::class, 'checkIn'])->name('tenant.hr.attendance.checkin');
        Route::post('/rh/pointages/{attendance}/depart', [HrController::class, 'checkOut'])->name('tenant.hr.attendance.checkout');
    });

    Route::get('/apres-vente', [AfterSalesController::class, 'index'])->name('tenant.after_sales.index');
    Route::post('/apres-vente/dossiers', [AfterSalesController::class, 'storeCase'])->name('tenant.after_sales.cases.store');
    Route::post('/apres-vente/fidelite', [AfterSalesController::class, 'awardLoyaltyPoints'])->name('tenant.after_sales.loyalty');

    Route::middleware('tenant.permission:reports.view')->group(function () {
        Route::get('/rapports', [ReportController::class, 'index'])->name('tenant.reports.index');
        Route::post('/rapports/depenses', [ReportController::class, 'storeExpense'])->name('tenant.reports.expenses.store');
    });

    // Messagerie & Notifications internes / clients
    Route::get('/messagerie', [MessageController::class, 'index'])->name('tenant.messages.index');
    Route::post('/messagerie', [MessageController::class, 'store'])->name('tenant.messages.store');
    Route::post('/messagerie/{message}/repondre', [MessageController::class, 'reply'])->name('tenant.messages.reply');
    Route::post('/messagerie/{message}/lu', [MessageController::class, 'markRead'])->name('tenant.messages.read');

    // Gestion Marketplace côté Garage (Catégories, Sous-catégories, Produits, Commandes)
    Route::prefix('marketplace-gestion')->name('tenant.marketplace.')->group(function () {
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
    Route::get('/affiliation', [AffiliationController::class, 'index'])->name('tenant.affiliation.index');
    Route::post('/affiliation', [AffiliationController::class, 'store'])->middleware('tenant.writable')->name('tenant.affiliation.store');
    Route::post('/affiliation/payer-abonnement', [AffiliationController::class, 'paySubscription'])->name('tenant.affiliation.pay');
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
        Route::get('/messagerie', fn () => view('admin.dashboard', ['section' => 'messages']))->name('messages');
        Route::get('/affiliation', fn () => view('admin.dashboard', ['section' => 'affiliation']))->name('affiliation');
        Route::get('/categories', fn () => view('admin.dashboard', ['section' => 'categories']))->name('categories');
        Route::get('/bannieres', fn () => view('admin.dashboard', ['section' => 'banners']))->name('banners');
        Route::get('/temoignages', fn () => view('admin.dashboard', ['section' => 'testimonials']))->name('testimonials');
        Route::get('/pays', fn () => view('admin.dashboard', ['section' => 'countries']))->name('countries');
        Route::get('/conditions-utilisation', fn () => view('admin.dashboard', ['section' => 'terms']))->name('terms');
        Route::get('/politique-confidentialite', fn () => view('admin.dashboard', ['section' => 'privacy']))->name('privacy');
        Route::get('/securite', fn () => view('admin.dashboard', ['section' => 'security']))->name('security');
        Route::get('/administrateurs', fn () => view('admin.dashboard', ['section' => 'admins']))->name('admins');
        Route::get('/parametres', fn () => view('admin.dashboard', ['section' => 'settings']))->name('settings');
        Route::get('/profil', fn () => view('admin.dashboard', ['section' => 'profile']))->name('profile');
    });
});

Route::get('/create-symlink', function () {
    if (is_link(public_path('storage'))) {
        return 'Le lien symbolique existe déjà !';
    }

    try {
        symlink(
            storage_path('app/public'),
            public_path('storage')
        );
        return 'Lien symbolique créé avec succès !';
    } catch (\Exception $e) {
        return 'Erreur : ' . $e->getMessage();
    }
});
