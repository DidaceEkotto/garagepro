<?php

namespace App\Http\Controllers;

use App\Mail\ContactMessageMail;
use App\Models\PlatformSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Contact', [
            'appName' => config('app.name', 'GaragePro'),
            'structureEmail' => PlatformSetting::structureEmail(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'subject' => ['required', 'string', 'min:3', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
        ]);

        $recipient = PlatformSetting::structureEmail();

        Mail::to($recipient)->send(new ContactMessageMail(
            senderName: $data['name'],
            senderEmail: $data['email'],
            senderPhone: $data['phone'] ?? null,
            contactSubject: $data['subject'],
            bodyMessage: $data['message'],
        ));

        return back()->with('success', __('contact.sent'));
    }
}
