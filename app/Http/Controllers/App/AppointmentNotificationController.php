<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AppointmentNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = AppointmentNotification::query()
            ->where('user_id', $request->user()->getKey())
            ->whereNull('read_at')
            ->with('appointment.customer', 'appointment.barber', 'appointment.service')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (AppointmentNotification $notification): array => [
                'id' => $notification->getKey(),
                'message' => sprintf(
                    '%s agendou %s com %s às %s.',
                    $notification->appointment->customer->name,
                    $notification->appointment->service->name,
                    $notification->appointment->barber->name,
                    $notification->appointment->starts_at->format('d/m H:i'),
                ),
                'url' => route('app.agenda.index', [
                    'date' => $notification->appointment->starts_at->toDateString(),
                    'barber_id' => $notification->appointment->barber_id,
                ]),
            ]);

        return response()->json(['data' => $notifications]);
    }

    public function markAsRead(
        Request $request,
        AppointmentNotification $notification,
    ): JsonResponse {
        abort_unless((int) $notification->user_id === (int) $request->user()->getKey(), 404);

        $notification->update(['read_at' => now()]);

        return response()->json(status: 204);
    }
}
