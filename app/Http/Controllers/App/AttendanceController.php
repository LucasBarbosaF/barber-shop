<?php

namespace App\Http\Controllers\App;

use App\Application\Attendance\Actions\AddAttendanceItem;
use App\Application\Attendance\Actions\CloseAttendance;
use App\Application\Attendance\Actions\OpenAttendance;
use App\Application\Payments\PaymentBalance;
use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Barber;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function show(Request $request, Attendance $attendance): JsonResponse|View
    {
        $this->authorize('attendance.view');
        $this->authorizeBarberOwnsAttendance($request, $attendance);
        $attendance->load([
            'items.service',
            'appointment.customer',
            'appointment.barber',
            'appointment.service',
        ]);

        if ($request->expectsJson()) {
            return response()->json(['data' => $attendance]);
        }

        $services = $attendance->status === AttendanceStatus::Open
            ? $attendance->appointment->barber->services()
                ->where(function ($query) use ($attendance): void {
                    $query->where('services.is_active', true)
                        ->orWhere('services.id', $attendance->appointment->service_id);
                })
                ->orderBy('services.name')
                ->get(['services.id', 'services.name', 'services.price', 'services.duration_minutes'])
            : collect();
        $payments = $request->user()?->can('cash.view')
            ? Payment::query()
                ->with('refunds')
                ->where('attendance_id', $attendance->getKey())
                ->oldest()
                ->get()
            : collect();

        return view('app.attendances.show', [
            'attendance' => $attendance,
            'services' => $services,
            'payments' => $payments,
            'totalDue' => PaymentBalance::totalDue($attendance),
            'totalPaid' => PaymentBalance::totalPaid($attendance),
        ]);
    }

    public function open(
        Request $request,
        Appointment $appointment,
        OpenAttendance $openAttendance,
    ): JsonResponse|RedirectResponse {
        $this->authorize('attendance.manage');
        $this->authorizeBarberOwnsAppointment($request, $appointment);

        $result = $openAttendance->execute(['appointment' => $appointment]);

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.attendances.show', $result['attendance'])
                ->with('status', 'Atendimento iniciado.');
        }

        return response()->json(['data' => $result['attendance']], 201);
    }

    public function addItem(
        Request $request,
        Attendance $attendance,
        AddAttendanceItem $addAttendanceItem,
    ): JsonResponse|RedirectResponse {
        $this->authorize('attendance.manage');
        $this->authorizeBarberOwnsAttendance($request, $attendance);

        $attributes = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'quantity' => ['sometimes', 'integer', 'between:1,1000'],
        ]);
        $service = Service::query()->findOrFail($attributes['service_id']);

        $result = $addAttendanceItem->execute([
            'attendance' => $attendance,
            'service' => $service,
            'quantity' => (int) ($attributes['quantity'] ?? 1),
        ]);

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.attendances.show', $attendance)
                ->with('status', 'Serviço adicionado ao atendimento.');
        }

        return response()->json(['data' => $result['item']], 201);
    }

    public function close(
        Request $request,
        Attendance $attendance,
        CloseAttendance $closeAttendance,
    ): JsonResponse|RedirectResponse {
        $this->authorize('attendance.manage');
        $this->authorizeBarberOwnsAttendance($request, $attendance);

        $result = $closeAttendance->execute(['attendance' => $attendance]);

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.attendances.show', $result['attendance'])
                ->with('status', 'Atendimento fechado.');
        }

        return response()->json(['data' => $result['attendance']]);
    }

    private function authorizeBarberOwnsAttendance(Request $request, Attendance $attendance): void
    {
        $this->authorizeBarberOwnsAppointment($request, $attendance->appointment);
    }

    private function authorizeBarberOwnsAppointment(Request $request, Appointment $appointment): void
    {
        $membership = $request->user()?->memberships()
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where('is_active', true)
            ->first();

        if ($membership?->role === MembershipRole::Barber) {
            $barber = Barber::query()
                ->where('user_id', $request->user()?->getKey())
                ->first();

            abort_unless(
                $barber !== null && (int) $appointment->barber_id === (int) $barber->getKey(),
                403,
            );
        }
    }
}
