<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetOtpMail;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GreenApiService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Réinitialisation sécurisée du mot de passe réservée au propriétaire principal du compte.
 * Flux identique à l'inscription : Choix canal (E-mail / WhatsApp) -> OTP 5 caractères -> Nouveau mot de passe.
 * Protections : anti-DDoS (RateLimiter IP + e-mail), honeypot anti-bot, empreinte session IP/UA, masquage anti-phishing.
 */
class PasswordResetOtpController extends Controller
{
    private const SESSION_KEY = 'pending_password_reset';

    public function showRequest(TenantContext $tenantContext): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'tenantName' => $tenantContext->getTenant()?->name,
        ]);
    }

    public function sendOtp(
        Request $request,
        TenantContext $tenantContext,
        GreenApiService $greenApi
    ): RedirectResponse {
        // 1. Honeypot anti-bot : un humain ne remplit jamais ce champ caché
        if (trim((string) $request->input('website', '')) !== '') {
            AuditLog::record('auth.password_reset_honeypot', request: $request);

            return back()->withErrors([
                'email' => 'Requête bloquée par la protection anti-bot.',
            ]);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'channel' => ['required', 'in:email,whatsapp'],
        ]);

        $email = strtolower(trim($validated['email']));
        $channel = $validated['channel'];
        $ip = (string) $request->ip();

        // 2. Anti-DDoS / Anti-Brute-Force (IP : 5 req / 15 min, Email : 3 req / 15 min)
        $ipKey = 'pwd_reset_ip:'.sha1($ip);
        $emailKey = 'pwd_reset_email:'.sha1($email);

        if (RateLimiter::tooManyAttempts($ipKey, 5)) {
            $seconds = RateLimiter::availableIn($ipKey);
            AuditLog::record('auth.password_reset_rate_limited', request: $request, metadata: ['scope' => 'ip', 'ip' => $ip]);

            return back()->withErrors([
                'email' => "Trop de demandes depuis cette connexion. Veuillez patienter {$seconds} secondes.",
            ]);
        }

        if (RateLimiter::tooManyAttempts($emailKey, 3)) {
            $seconds = RateLimiter::availableIn($emailKey);
            AuditLog::record('auth.password_reset_rate_limited', request: $request, metadata: ['scope' => 'email']);

            return back()->withErrors([
                'email' => "Trop de demandes pour ce compte. Veuillez patienter {$seconds} secondes.",
            ]);
        }

        RateLimiter::hit($ipKey, 900);
        RateLimiter::hit($emailKey, 900);

        // 3. Recherche du compte (isolé au tenant si on est sur un sous-domaine)
        $query = User::withoutTenantScope()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true);

        if ($tenantContext->check()) {
            $query->where('tenant_id', $tenantContext->id());
        }

        $user = $query->first();
        if (! $user || ! $user->tenant_id) {
            AuditLog::record('auth.password_reset_unknown_email', request: $request);

            return back()->withErrors([
                'email' => 'Aucun compte propriétaire actif ne correspond à cette adresse e-mail.',
            ]);
        }

        // 4. Vérification stricte : SEUL le propriétaire principal du compte (1er utilisateur du tenant) est autorisé
        $owner = User::withoutTenantScope()
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('id')
            ->first();

        if (! $owner || (int) $owner->id !== (int) $user->id) {
            AuditLog::record(
                'auth.password_reset_non_owner_blocked',
                subject: $user,
                tenantId: (int) $user->tenant_id,
                request: $request
            );

            return back()->withErrors([
                'email' => 'Seul le propriétaire principal du compte peut réinitialiser son mot de passe ici. Si vous êtes un membre de l\'équipe, contactez le gérant de votre espace.',
            ]);
        }

        $tenant = Tenant::find($user->tenant_id);
        $garageName = $tenant?->name ?? 'MboaCar';
        $phone = trim((string) ($user->phone ?: ($tenant?->phone ?? '')));

        $code = $this->generateOtpCode();
        $maskedIp = $this->maskIp($ip);

        if ($channel === 'whatsapp') {
            if ($phone === '') {
                return back()->withErrors([
                    'channel' => 'Aucun numéro WhatsApp n\'est enregistré sur ce compte propriétaire. Veuillez choisir l\'envoi par e-mail.',
                ]);
            }

            if (! $greenApi->isConfigured()) {
                return back()->withErrors([
                    'channel' => 'Le canal WhatsApp est temporairement indisponible. Veuillez choisir l\'envoi par e-mail.',
                ]);
            }

            $waMessage = "MboaCar Sécurité ({$garageName}) : Votre code de réinitialisation de mot de passe est {$code}. Valable 5 minutes. Ne partagez JAMAIS ce code.";
            $sendResult = $greenApi->sendMessage($phone, $waMessage);

            if (! ($sendResult['ok'] ?? false)) {
                return back()->withErrors([
                    'channel' => "Impossible d'envoyer le code sur WhatsApp pour le moment. Essayez l'envoi par e-mail.",
                ]);
            }
        } else {
            try {
                Mail::to($user->email)->send(new PasswordResetOtpMail(
                    code: $code,
                    ownerName: $user->name,
                    garageName: $garageName,
                    maskedIp: $maskedIp,
                ));
            } catch (\Throwable $e) {
                return back()->withErrors([
                    'email' => "Impossible d'envoyer l'e-mail de vérification pour le moment. Veuillez réessayer.",
                ]);
            }
        }

        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'owner_name' => $user->name,
            'garage_name' => $garageName,
            'email' => $user->email,
            'phone' => $phone,
            'channel' => $channel,
            'otp_hash' => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(5)->timestamp,
            'resend_available_at' => now()->addMinutes(2)->timestamp,
            'attempts' => 0,
            'verified' => false,
            'fingerprint' => $this->sessionFingerprint($request),
        ]);

        AuditLog::record(
            'auth.password_reset_otp_sent',
            subject: $user,
            tenantId: (int) $user->tenant_id,
            request: $request,
            metadata: ['channel' => $channel]
        );

        return redirect()->route('password.otp');
    }

    public function showOtp(Request $request): Response|RedirectResponse
    {
        $pending = $this->validPendingSession($request);
        if (! $pending) {
            return redirect()->route('password.request');
        }

        if (! empty($pending['verified'])) {
            return redirect()->route('password.reset.form');
        }

        return Inertia::render('Auth/VerifyResetOtp', [
            'maskedEmail' => $this->maskEmail((string) ($pending['email'] ?? '')),
            'maskedPhone' => $this->maskPhone((string) ($pending['phone'] ?? '')),
            'garageName' => $pending['garage_name'] ?? 'MboaCar',
            'channel' => $pending['channel'] ?? 'email',
            'expiresAt' => (int) ($pending['otp_expires_at'] ?? now()->timestamp),
            'resendAvailableAt' => (int) ($pending['resend_available_at'] ?? now()->timestamp),
            'attempts' => (int) ($pending['attempts'] ?? 0),
            'maxAttempts' => 3,
        ]);
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $pending = $this->validPendingSession($request);
        if (! $pending) {
            return redirect()->route('password.request')->withErrors([
                'email' => 'Votre session de réinitialisation a expiré. Veuillez recommencer.',
            ]);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:5'],
        ]);

        if (now()->timestamp > (int) ($pending['otp_expires_at'] ?? 0)) {
            return back()->withErrors([
                'code' => 'Ce code de vérification a expiré (validité 5 minutes). Veuillez demander un nouveau code.',
            ]);
        }

        $inputCode = strtoupper(trim($validated['code']));
        if (Hash::check($inputCode, (string) ($pending['otp_hash'] ?? ''))) {
            $pending['verified'] = true;
            $request->session()->put(self::SESSION_KEY, $pending);

            return redirect()->route('password.reset.form');
        }

        $attempts = (int) ($pending['attempts'] ?? 0) + 1;
        $pending['attempts'] = $attempts;

        // 3 échecs sur WhatsApp -> bascule automatique vers un nouveau code envoyé par e-mail (comme à l'inscription)
        if ($attempts >= 3 && ($pending['channel'] ?? 'email') === 'whatsapp') {
            $newCode = $this->generateOtpCode();
            $email = (string) $pending['email'];

            try {
                Mail::to($email)->send(new PasswordResetOtpMail(
                    code: $newCode,
                    ownerName: (string) ($pending['owner_name'] ?? 'Propriétaire'),
                    garageName: (string) ($pending['garage_name'] ?? 'MboaCar'),
                    maskedIp: $this->maskIp((string) $request->ip()),
                ));
            } catch (\Throwable) {
                $request->session()->forget(self::SESSION_KEY);

                return redirect()->route('password.request')->withErrors([
                    'email' => 'Trop de tentatives incorrectes. Veuillez recommencer la demande.',
                ]);
            }

            $pending['channel'] = 'email';
            $pending['attempts'] = 0;
            $pending['otp_hash'] = Hash::make($newCode);
            $pending['otp_expires_at'] = now()->addMinutes(5)->timestamp;
            $pending['resend_available_at'] = now()->addMinutes(2)->timestamp;
            $request->session()->put(self::SESSION_KEY, $pending);

            return back()->with(
                'warning',
                '3 tentatives sans succès. Un nouveau code de sécurité vient d\'être envoyé à votre adresse e-mail ('.$this->maskEmail($email).').'
            );
        }

        // 3 échecs sur E-mail -> invalidation immédiate par sécurité
        if ($attempts >= 3) {
            $request->session()->forget(self::SESSION_KEY);
            AuditLog::record(
                'auth.password_reset_max_attempts',
                tenantId: (int) ($pending['tenant_id'] ?? 0) ?: null,
                request: $request
            );

            return redirect()->route('password.request')->withErrors([
                'email' => 'Nombre maximum de tentatives atteint (3/3). Par sécurité, veuillez effectuer une nouvelle demande.',
            ]);
        }

        $request->session()->put(self::SESSION_KEY, $pending);

        return back()->withErrors([
            'code' => "Code de vérification incorrect (tentative {$attempts}/3).",
        ]);
    }

    public function resendOtp(Request $request, GreenApiService $greenApi): RedirectResponse
    {
        $pending = $this->validPendingSession($request);
        if (! $pending) {
            return redirect()->route('password.request');
        }

        $resendAvailableAt = (int) ($pending['resend_available_at'] ?? 0);
        if (now()->timestamp < $resendAvailableAt) {
            $remaining = $resendAvailableAt - now()->timestamp;

            return back()->withErrors([
                'code' => "Veuillez patienter encore {$remaining} seconde(s) avant de demander un nouveau code.",
            ]);
        }

        $newCode = $this->generateOtpCode();
        $channel = $pending['channel'] ?? 'email';
        $garageName = (string) ($pending['garage_name'] ?? 'MboaCar');

        if ($channel === 'whatsapp' && ! empty($pending['phone'])) {
            $sendResult = $greenApi->sendMessage(
                (string) $pending['phone'],
                "MboaCar Sécurité ({$garageName}) : Votre nouveau code de réinitialisation est {$newCode}. Valable 5 minutes."
            );
            if (! ($sendResult['ok'] ?? false)) {
                return back()->withErrors([
                    'code' => 'Impossible de renvoyer le code WhatsApp pour le moment.',
                ]);
            }
            $successMsg = 'Un nouveau code de sécurité a été envoyé sur votre WhatsApp.';
        } else {
            Mail::to((string) $pending['email'])->send(new PasswordResetOtpMail(
                code: $newCode,
                ownerName: (string) ($pending['owner_name'] ?? 'Propriétaire'),
                garageName: $garageName,
                maskedIp: $this->maskIp((string) $request->ip()),
            ));
            $successMsg = 'Un nouveau code de sécurité a été envoyé à votre adresse e-mail.';
        }

        $pending['otp_hash'] = Hash::make($newCode);
        $pending['otp_expires_at'] = now()->addMinutes(5)->timestamp;
        $pending['resend_available_at'] = now()->addMinutes(2)->timestamp;
        $pending['attempts'] = 0;
        $request->session()->put(self::SESSION_KEY, $pending);

        return back()->with('success', $successMsg);
    }

    public function showResetForm(Request $request): Response|RedirectResponse
    {
        $pending = $this->validPendingSession($request);
        if (! $pending || empty($pending['verified'])) {
            return redirect()->route('password.request');
        }

        return Inertia::render('Auth/ResetPassword', [
            'garageName' => $pending['garage_name'] ?? 'MboaCar',
            'maskedEmail' => $this->maskEmail((string) ($pending['email'] ?? '')),
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $pending = $this->validPendingSession($request);
        if (! $pending || empty($pending['verified'])) {
            return redirect()->route('password.request')->withErrors([
                'email' => 'Session de réinitialisation invalide ou expirée.',
            ]);
        }

        $validated = $request->validate([
            'password' => [
                'required',
                'string',
                'min:8',
                'max:128',
                'confirmed',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[_\-@!#$%^&*()+=\[\]{};:,.<>?\/]/',
            ],
        ], [
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.regex' => 'Le mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre et un caractère spécial (_ - @ ...).',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ]);

        $user = User::withoutTenantScope()
            ->where('id', (int) $pending['user_id'])
            ->where('tenant_id', (int) $pending['tenant_id'])
            ->where('is_active', true)
            ->first();

        if (! $user) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('password.request')->withErrors([
                'email' => 'Compte introuvable ou désactivé.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ])->save();

        $request->session()->forget(self::SESSION_KEY);
        $request->session()->regenerate();

        AuditLog::record(
            'auth.password_reset_completed',
            subject: $user,
            actor: $user,
            tenantId: (int) $user->tenant_id,
            request: $request
        );

        return redirect()->route('tenant.login')->with(
            'success',
            'Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.'
        );
    }

    /**
     * Vérifie que la session OTP existe et correspond à l'empreinte IP + User-Agent (anti-détournement).
     *
     * @return ?array<string, mixed>
     */
    private function validPendingSession(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION_KEY);
        if (! is_array($pending) || empty($pending['user_id'])) {
            return null;
        }

        $expectedFp = (string) ($pending['fingerprint'] ?? '');
        if ($expectedFp === '' || ! hash_equals($expectedFp, $this->sessionFingerprint($request))) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $pending;
    }

    private function sessionFingerprint(Request $request): string
    {
        return hash('sha256', (string) $request->ip().'|'.substr((string) $request->userAgent(), 0, 200));
    }

    private function generateOtpCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '***';
        }
        [$local, $domain] = explode('@', $email, 2);
        $prefix = substr($local, 0, min(2, strlen($local)));

        return $prefix.'***@'.$domain;
    }

    private function maskPhone(string $phone): string
    {
        $clean = trim($phone);
        if (strlen($clean) <= 5) {
            return '***';
        }

        return substr($clean, 0, 6).' *** '.substr($clean, -2);
    }

    private function maskIp(string $ip): string
    {
        if (str_contains($ip, '.')) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                return "{$parts[0]}.{$parts[1]}.***.{$parts[3]}";
            }
        }

        return $ip !== '' ? substr($ip, 0, 8).'***' : '';
    }
}
