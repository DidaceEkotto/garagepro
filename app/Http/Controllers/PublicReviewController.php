<?php

namespace App\Http\Controllers;

use App\Models\ClientReview;
use App\Models\Testimonial;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicReviewController extends Controller
{
    public function show(string $token): Response
    {
        $review = ClientReview::withoutGlobalScopes()
            ->with(['tenant:id,name,phone,address,logo_path', 'customer:id,name', 'repairOrder:id,order_number,vehicle_id', 'repairOrder.vehicle:id,brand,model,license_plate'])
            ->where('token', $token)
            ->firstOrFail();

        return Inertia::render('Public/ClientReview', [
            'review' => [
                'token' => $review->token,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'published_publicly' => $review->published_publicly,
                'submitted_at' => $review->submitted_at?->format('d/m/Y à H:i'),
                'order_number' => $review->repairOrder?->order_number,
                'vehicle_label' => trim(($review->repairOrder?->vehicle?->brand ?? '').' '.($review->repairOrder?->vehicle?->model ?? '').' '.($review->repairOrder?->vehicle?->license_plate ? "({$review->repairOrder->vehicle->license_plate})" : '')),
                'customer_name' => $review->customer?->name,
                'garage_name' => $review->tenant?->name ?? config('app.name', 'MboaCar'),
            ],
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $review = ClientReview::withoutGlobalScopes()
            ->with(['tenant', 'customer'])
            ->where('token', $token)
            ->firstOrFail();

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1500'],
            'publish_publicly' => ['nullable', 'boolean'],
        ]);

        $publish = (int) $validated['rating'] >= 4 && (bool) ($validated['publish_publicly'] ?? false);

        $review->update([
            'rating' => (int) $validated['rating'],
            'comment' => $validated['comment'] ?? null,
            'published_publicly' => $publish,
            'submitted_at' => now(),
        ]);

        if ($publish && ! empty($validated['comment'])) {
            Testimonial::updateOrCreate(
                [
                    'author_name' => $review->customer?->name ?? 'Client vérifié',
                    'garage_name' => $review->tenant?->name ?? 'Client Garage',
                ],
                [
                    'author_role' => 'Client vérifié',
                    'rating' => (int) $validated['rating'],
                    'quote' => $validated['comment'],
                    'is_published' => true,
                ]
            );
        }

        return back()->with('success', 'Merci pour votre avis ! Votre retour a bien été enregistré.');
    }
}
