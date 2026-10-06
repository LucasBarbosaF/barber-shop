<?php

namespace App\Http\Controllers\App;

use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Payments\Enums\PaymentEventType;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Barber;
use App\Models\Customer;
use App\Models\PaymentEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $tenantId = $this->tenantContext->id();
        $tenant = $tenantId === null ? null : Tenant::query()->find($tenantId);

        $canViewSchedule = $tenantId !== null && $user?->can('schedule.view') === true;
        $canViewAttendance = $tenantId !== null && $user?->can('attendance.view') === true;
        $canViewCustomers = $tenantId !== null && $user?->can('customers.view') === true;
        $canViewFinance = $tenantId !== null && (
            $user?->can('finance.view') === true || $user?->can('cash.view') === true
        );

        $membership = $tenantId === null
            ? null
            : $user?->memberships()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();
        $isBarber = $membership?->role === MembershipRole::Barber;
        $ownBarber = $isBarber
            ? Barber::query()->where('user_id', $user?->getKey())->first()
            : null;
        $today = Carbon::now(config('app.timezone'));
        $dayStartsAt = $today->copy()->startOfDay();
        $dayEndsAt = $dayStartsAt->copy()->addDay();
        $weekEndsAt = $dayStartsAt->copy()->addDays(7);

        $appointmentsToday = collect();
        $upcomingAppointments = collect();
        $dailyAppointmentCounts = collect();
        $todayAppointmentCount = 0;
        $openAttendanceCount = 0;
        $newCustomersToday = 0;
        $revenueToday = null;

        if ($canViewSchedule) {
            $barberFilter = $isBarber ? ($ownBarber?->getKey() ?? 0) : null;
            $appointmentsToday = Appointment::query()
                ->with(['customer', 'barber', 'service', 'attendance'])
                ->where('starts_at', '>=', $dayStartsAt)
                ->where('starts_at', '<', $dayEndsAt)
                ->when($barberFilter !== null, fn ($query) => $query->where('barber_id', $barberFilter))
                ->orderBy('starts_at')
                ->get();
            $todayAppointmentCount = $appointmentsToday->count();

            $upcomingAppointments = Appointment::query()
                ->with(['customer', 'barber', 'service'])
                ->where('starts_at', '>=', $dayEndsAt)
                ->where('starts_at', '<', $weekEndsAt)
                ->whereIn('status', AppointmentStatus::blockingValues())
                ->when($barberFilter !== null, fn ($query) => $query->where('barber_id', $barberFilter))
                ->orderBy('starts_at')
                ->limit(6)
                ->get();

            $dailyAppointmentCounts = Appointment::query()
                ->where('starts_at', '>=', $dayStartsAt)
                ->where('starts_at', '<', $weekEndsAt)
                ->whereIn('status', AppointmentStatus::blockingValues())
                ->when($barberFilter !== null, fn ($query) => $query->where('barber_id', $barberFilter))
                ->get(['starts_at'])
                ->groupBy(fn (Appointment $appointment): string => $appointment->starts_at
                    ->setTimezone(config('app.timezone'))
                    ->toDateString())
                ->map(fn ($appointments): int => $appointments->count());
        }

        if ($canViewAttendance) {
            $openAttendanceCount = Attendance::query()
                ->where('status', AttendanceStatus::Open)
                ->when(
                    $isBarber,
                    fn ($query) => $query->whereHas(
                        'appointment',
                        fn ($appointmentQuery) => $appointmentQuery->where('barber_id', $ownBarber?->getKey() ?? 0),
                    ),
                )
                ->count();
        }

        if ($canViewCustomers) {
            $newCustomersToday = Customer::query()
                ->where('created_at', '>=', $dayStartsAt)
                ->where('created_at', '<', $dayEndsAt)
                ->count();
        }

        if ($canViewFinance) {
            $paymentsReceived = PaymentEvent::query()
                ->where('type', PaymentEventType::PaymentReceived)
                ->where('created_at', '>=', $dayStartsAt)
                ->where('created_at', '<', $dayEndsAt)
                ->sum('amount');
            $paymentsRefunded = PaymentEvent::query()
                ->where('type', PaymentEventType::PaymentRefunded)
                ->where('created_at', '>=', $dayStartsAt)
                ->where('created_at', '<', $dayEndsAt)
                ->sum('amount');
            $revenueToday = (float) $paymentsReceived - (float) $paymentsRefunded;
        }

        return view('app.dashboard', compact(
            'appointmentsToday',
            'canViewAttendance',
            'canViewCustomers',
            'canViewFinance',
            'canViewSchedule',
            'dailyAppointmentCounts',
            'isBarber',
            'newCustomersToday',
            'openAttendanceCount',
            'revenueToday',
            'tenant',
            'today',
            'todayAppointmentCount',
            'upcomingAppointments',
        ));
    }
}
