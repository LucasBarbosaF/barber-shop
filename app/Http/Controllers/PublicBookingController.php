<?php

namespace App\Http\Controllers;

use App\Application\Agenda\Actions\CreatePublicAppointment;
use App\Application\Agenda\Actions\ListAvailableSlots;
use App\Domain\Tenant\Models\Tenant;
use App\Models\Barber;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicBookingController extends Controller
{
    public function show(Request $request): View
    {
        $tenant = $this->tenant($request);
        $barbers = Barber::query()
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->whereHas('businessHours')
            ->whereHas('services', fn ($query) => $query->where('services.is_active', true))
            ->with(['services' => fn ($query) => $query
                ->where('services.is_active', true)
                ->select('services.id')])
            ->orderBy('name')
            ->get(['id', 'name', 'bio']);
        $serviceIds = $barbers->flatMap(fn (Barber $barber) => $barber->services->pluck('id'))
            ->unique()
            ->values();
        $services = Service::query()
            ->where('is_active', true)
            ->whereIn('id', $serviceIds)
            ->orderBy('name')
            ->get(['id', 'name', 'duration_minutes', 'price']);

        return view('booking.show', compact('tenant', 'barbers', 'services'));
    }

    public function availability(Request $request, ListAvailableSlots $listAvailableSlots): JsonResponse
    {
        $attributes = $request->validate([
            'barber_id' => ['required', 'integer', 'min:1'],
            'service_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $result = $listAvailableSlots->execute([
            'barber_id' => (int) $attributes['barber_id'],
            'service_id' => (int) $attributes['service_id'],
            'date' => $attributes['date'],
        ]);

        return response()->json(['data' => $result['slots']]);
    }

    public function store(Request $request, CreatePublicAppointment $createAppointment): RedirectResponse
    {
        $phone = preg_replace('/\D+/', '', (string) $request->input('customer_phone'));
        $request->merge(['customer_phone' => $phone]);

        $attributes = $request->validate([
            'barber_id' => ['required', 'integer', 'min:1'],
            'service_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'regex:/^\d{10,11}$/'],
        ], [
            'customer_phone.regex' => 'Informe um telefone com DDD válido.',
        ]);

        $result = $createAppointment->execute([
            'barber_id' => (int) $attributes['barber_id'],
            'service_id' => (int) $attributes['service_id'],
            'date' => $attributes['date'],
            'time' => $attributes['time'],
            'customer_name' => $attributes['customer_name'],
            'customer_phone' => $attributes['customer_phone'],
        ]);

        return redirect()
            ->route('booking.show', $this->tenant($request)->slug)
            ->with('booking_status', sprintf(
                'Agendamento confirmado para %s às %s.',
                $result['appointment']->starts_at->format('d/m/Y'),
                $result['appointment']->starts_at->format('H:i'),
            ));
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('public_booking_tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }
}
