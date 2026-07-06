<?php

namespace Ushahidi\Modules\V5\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Ushahidi\Modules\V5\Models\ContactUs;

class ContactUsController extends V5Controller
{
    /**
     * Submit a public contact form.
     * POST /api/v3/contact-us
     * Public — no auth required.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|max:255',
            'phone_number' => 'nullable|string|max:255',
            'subject'      => 'required|string|max:255',
            'message'      => 'required|string|max:5000',
        ]);

        ContactUs::create([
            'name'         => $data['name'],
            'email'        => $data['email'],
            'phone_number' => $data['phone_number'] ?? null,
            'subject'      => $data['subject'],
            'message'      => $data['message'],
            'status'       => 0,
            'created'      => time(),
            'updated'      => time(),
        ]);

        // Send email to configured contact address
        $recipient = config('liberia.contact_us_email', env('CONTACT_US_EMAIL'));
        if ($recipient) {
            $this->sendNotificationEmail($recipient, $data);
        } else {
            \Log::warning('Contact Us email not sent: CONTACT_US_EMAIL is not configured');
        }

        return response()->json(['success' => true]);
    }

    private function sendNotificationEmail(string $recipient, array $data): void
    {
        try {
            Mail::send([], [], function ($message) use ($recipient, $data) {
                $message->to($recipient)
                    ->subject('iReport Liberia: ' . $data['subject'])
                    ->html(
                        '<p><strong>From:</strong> ' . e($data['name']) . ' &lt;' . e($data['email']) . '&gt;</p>' .
                        ($data['phone_number'] ? '<p><strong>Phone:</strong> ' . e($data['phone_number']) . '</p>' : '') .
                        '<p><strong>Subject:</strong> ' . e($data['subject']) . '</p>' .
                        '<p><strong>Message:</strong></p>' .
                        '<p>' . nl2br(e($data['message'])) . '</p>'
                    );
            });
        } catch (\Exception $e) {
            // Log silently — submission is already saved to DB
            \Log::warning('Contact Us email failed: ' . $e->getMessage());
        }
    }
}
