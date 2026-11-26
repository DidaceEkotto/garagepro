<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Mail\RichMessageMail;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\PlatformMessage;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    public function index(TenantContext $tenantContext): Response
    {
        $tenantId = $tenantContext->id();

        $messages = PlatformMessage::where('tenant_id', $tenantId)
            ->latest('id')
            ->take(50)
            ->get()
            ->map(function (PlatformMessage $msg) {
                $attachments = collect($msg->attachments ?? [])->map(fn ($att, $idx) => [
                    'index' => $idx,
                    'name' => $att['original_name'] ?? 'fichier',
                    'size' => $att['size'] ?? 0,
                    'downloadUrl' => route('messages.attachment.download', ['message' => $msg->id, 'index' => $idx]),
                ])->values();

                return [
                    'id' => $msg->id,
                    'parent_id' => $msg->parent_id,
                    'sender_type' => $msg->sender_type,
                    'sender_name' => $msg->sender_name,
                    'sender_email' => $msg->sender_email,
                    'recipient_type' => $msg->recipient_type,
                    'recipient_email' => $msg->recipient_email,
                    'subject' => $msg->subject,
                    'body' => $msg->body,
                    'attachments' => $attachments,
                    'is_read' => $msg->is_read,
                    'created_at' => $msg->created_at?->toIso8601String(),
                ];
            });

        // Marquer les messages reçus par le tenant comme lus à l'ouverture de la messagerie
        PlatformMessage::where('tenant_id', $tenantId)
            ->where('recipient_type', PlatformMessage::TYPE_TENANT)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return Inertia::render('Tenant/Messages/Index', [
            'messages' => $messages,
            'customers' => Customer::whereNotNull('email')
                ->where('email', '!=', '')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'phone']),
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->getTenant();
        $user = $request->user('web');

        $data = $request->validate([
            'recipient_scope' => ['required', Rule::in(['admin', 'customers'])],
            'customer_ids' => ['required_if:recipient_scope,customers', 'array'],
            'customer_ids.*' => ['integer', Rule::exists('customers', 'id')->where('tenant_id', $tenant->id)],
            'subject' => ['required', 'string', 'min:2', 'max:200'],
            'body' => ['required', 'string', 'min:2', 'max:5000'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,txt', 'max:10240'],
        ]);

        $storedAttachments = $this->storeUploadedAttachments($request->file('attachments', []), $tenant->id);

        if ($data['recipient_scope'] === 'admin') {
            $msg = PlatformMessage::create([
                'tenant_id' => $tenant->id,
                'sender_type' => PlatformMessage::TYPE_TENANT,
                'sender_id' => $user->id,
                'sender_name' => $tenant->name.' — '.$user->name,
                'sender_email' => $user->email,
                'recipient_type' => PlatformMessage::TYPE_ADMIN,
                'subject' => $data['subject'],
                'body' => $data['body'],
                'attachments' => $storedAttachments,
                'is_read' => false,
            ]);

            AuditLog::record('message.sent_to_admin', subject: $msg, actor: $user, tenantId: $tenant->id, request: $request);

            return back()->with('success', 'Message envoyé dans la messagerie interne de l\'administrateur (sans e-mail).');
        }

        $customers = Customer::whereIn('id', $data['customer_ids'] ?? [])
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();

        if ($customers->isEmpty()) {
            return back()->with('error', 'Aucun client sélectionné ne dispose d\'une adresse e-mail valide.');
        }

        foreach ($customers as $customer) {
            $msg = PlatformMessage::create([
                'tenant_id' => $tenant->id,
                'sender_type' => PlatformMessage::TYPE_TENANT,
                'sender_id' => $user->id,
                'sender_name' => $tenant->name,
                'sender_email' => $user->email,
                'recipient_type' => PlatformMessage::TYPE_CUSTOMER,
                'recipient_id' => $customer->id,
                'recipient_email' => $customer->email,
                'subject' => $data['subject'],
                'body' => $data['body'],
                'attachments' => $storedAttachments,
                'is_read' => true,
                'read_at' => now(),
            ]);

            $links = $this->buildSignedAttachmentLinks($msg);

            Mail::to($customer->email)->send(new RichMessageMail(
                garageName: $tenant->name,
                recipientName: $customer->name,
                subjectLine: $data['subject'],
                bodyText: $data['body'],
                attachmentLinks: $links,
            ));
        }

        AuditLog::record('message.sent_to_customers', actor: $user, tenantId: $tenant->id, metadata: [
            'count' => $customers->count(),
        ], request: $request);

        return back()->with('success', "Message envoyé par e-mail à {$customers->count()} client(s).");
    }

    public function reply(Request $request, PlatformMessage $message, TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->getTenant();
        if ((int) $message->tenant_id !== (int) $tenant->id) {
            abort(403);
        }

        $user = $request->user('web');

        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:5000'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,txt', 'max:10240'],
        ]);

        $storedAttachments = $this->storeUploadedAttachments($request->file('attachments', []), $tenant->id);
        $subject = str_starts_with($message->subject, 'Re:') ? $message->subject : 'Re: '.$message->subject;

        // Marquer le message original comme lu
        if (! $message->is_read) {
            $message->update(['is_read' => true, 'read_at' => now()]);
        }

        // Si le message provient d'un Admin (ou est destiné à un Admin) => messagerie interne uniquement (pas d'email)
        if ($message->sender_type === PlatformMessage::TYPE_ADMIN || $message->recipient_type === PlatformMessage::TYPE_ADMIN) {
            PlatformMessage::create([
                'tenant_id' => $tenant->id,
                'parent_id' => $message->id,
                'sender_type' => PlatformMessage::TYPE_TENANT,
                'sender_id' => $user->id,
                'sender_name' => $tenant->name.' — '.$user->name,
                'sender_email' => $user->email,
                'recipient_type' => PlatformMessage::TYPE_ADMIN,
                'subject' => $subject,
                'body' => $data['body'],
                'attachments' => $storedAttachments,
                'is_read' => false,
            ]);

            return back()->with('success', 'Réponse envoyée dans la messagerie interne de l\'administrateur.');
        }

        // Sinon => réponse envoyée par email au client avec liens de téléchargement
        $targetEmail = $message->sender_type === PlatformMessage::TYPE_CUSTOMER
            ? $message->sender_email
            : $message->recipient_email;

        $targetName = $message->sender_type === PlatformMessage::TYPE_CUSTOMER
            ? $message->sender_name
            : ($message->recipient_email ?? 'Client');

        $replyMsg = PlatformMessage::create([
            'tenant_id' => $tenant->id,
            'parent_id' => $message->id,
            'sender_type' => PlatformMessage::TYPE_TENANT,
            'sender_id' => $user->id,
            'sender_name' => $tenant->name,
            'sender_email' => $user->email,
            'recipient_type' => PlatformMessage::TYPE_CUSTOMER,
            'recipient_id' => $message->recipient_id,
            'recipient_email' => $targetEmail,
            'subject' => $subject,
            'body' => $data['body'],
            'attachments' => $storedAttachments,
            'is_read' => true,
            'read_at' => now(),
        ]);

        if (! empty($targetEmail)) {
            $links = $this->buildSignedAttachmentLinks($replyMsg);
            Mail::to($targetEmail)->send(new RichMessageMail(
                garageName: $tenant->name,
                recipientName: $targetName,
                subjectLine: $subject,
                bodyText: $data['body'],
                attachmentLinks: $links,
            ));
        }

        return back()->with('success', 'Réponse envoyée par e-mail avec les pièces jointes.');
    }

    public function markRead(PlatformMessage $message, TenantContext $tenantContext): RedirectResponse
    {
        if ((int) $message->tenant_id !== (int) $tenantContext->id()) {
            abort(403);
        }

        $message->update(['is_read' => true, 'read_at' => now()]);

        return back();
    }

    public function downloadAttachment(Request $request, PlatformMessage $message, int $index): StreamedResponse
    {
        $isSigned = $request->hasValidSignature();
        $isTenantOwner = Auth::guard('web')->check() && (int) Auth::guard('web')->user()->tenant_id === (int) $message->tenant_id;
        $isAdmin = Auth::guard('admin')->check();

        if (! $isSigned && ! $isTenantOwner && ! $isAdmin) {
            abort(403);
        }

        $attachments = $message->attachments ?? [];
        if (! isset($attachments[$index])) {
            abort(404);
        }

        $att = $attachments[$index];
        $path = (string) ($att['path'] ?? '');
        $expectedPrefix = "messages/{$message->tenant_id}/";

        if (
            $path === ''
            || str_contains($path, '..')
            || str_contains($path, "\0")
            || (! str_starts_with($path, $expectedPrefix) && ! str_starts_with($path, "tenants/{$message->tenant_id}/"))
            || ! Storage::disk('local')->exists($path)
        ) {
            abort(404);
        }

        return Storage::disk('local')->download($path, $att['original_name'] ?? basename($path));
    }

    /**
     * @param array<int, UploadedFile>|UploadedFile|null $files
     * @return array<int, array{path: string, original_name: string, mime_type: string, size: int}>
     */
    private function storeUploadedAttachments(mixed $files, int $tenantId): array
    {
        if (! is_array($files)) {
            $files = $files ? [$files] : [];
        }

        $stored = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $path = Storage::disk('local')->putFile("messages/{$tenantId}", $file);
            $stored[] = [
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
                'size' => (int) $file->getSize(),
            ];
        }

        return $stored;
    }

    /**
     * @return array<int, array{name: string, url: string, size: int}>
     */
    private function buildSignedAttachmentLinks(PlatformMessage $message): array
    {
        $links = [];
        foreach ($message->attachments ?? [] as $idx => $att) {
            $links[] = [
                'name' => $att['original_name'] ?? 'fichier',
                'size' => (int) ($att['size'] ?? 0),
                'url' => URL::temporarySignedRoute(
                    'messages.attachment.download',
                    now()->addDays(7),
                    ['message' => $message->id, 'index' => $idx]
                ),
            ];
        }

        return $links;
    }
}
