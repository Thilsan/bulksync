<?php

namespace App\Http\Controllers;

use App\Models\ProductRequest;
use App\Models\User;
use App\Services\ProductRequestWorkflow;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The Photoshoot Schedule: every request that needs a shoot, on one calendar.
 *
 * Scheduling used to live inside each request, which meant nobody could answer
 * "what are we shooting next week" without opening them one at a time. The room
 * is that answer — and it is deliberately read-only for everyone except the
 * photoshoot coordinator, so a calendar the whole company can see is still a
 * calendar only one person changes.
 */
class PhotoshootRoomController extends Controller implements HasMiddleware
{
    public function __construct(private ProductRequestWorkflow $workflow) {}

    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $request, \Closure $next) {
                abort_unless($request->user()?->hasFeature('product_request'), 403, 'You do not have access to Product Creation Requests.');
                return $next($request);
            }),
        ];
    }

    public function index(Request $request, #[CurrentUser] User $user): View
    {
        // Month being viewed. Anything unparseable falls back to this month
        // rather than erroring on a hand-edited URL.
        $month = rescue(
            fn () => Carbon::createFromFormat('Y-m', (string) $request->query('month', ''))->startOfMonth(),
            now()->startOfMonth(),
            report: false,
        );

        $shoots = ProductRequest::query()
            ->withPhotoshoot()
            ->with(['store', 'currentAssignments.user'])
            ->orderByRaw('photoshoot_scheduled_at is null')      // undated first: they need a date
            ->orderBy('photoshoot_scheduled_at')
            ->get();

        $filter = (string) $request->query('status', '');

        return view('product-requests.photoshoot-room', [
            'month'      => $month,
            'weeks'      => $this->calendar($month, $shoots),
            'shoots'     => $filter && isset(ProductRequest::SHOOT_STATUSES[$filter])
                ? $shoots->where('photoshoot_status', $filter)->values()
                : $shoots,
            'filter'     => $filter,
            'stats'      => $this->stats($shoots),
            'canEdit'    => $this->canEdit($user),
            'coordinator' => User::photoshootCoordinator(),
        ]);
    }

    /**
     * The coordinator's edit: when it happens, where, and where it has got to.
     *
     * Marking a shoot scheduled or completed moves the request itself as well —
     * one action, so the calendar and the workflow can't tell different stories.
     */
    public function update(Request $request, ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless($this->canEdit($user), 403, 'Only the photoshoot coordinator can change the calendar.');
        abort_if($productRequest->photoshoot_status === null, 404, 'This request does not need a photoshoot.');

        $data = $request->validate([
            'photoshoot_status'       => ['required', Rule::in(array_keys(ProductRequest::SHOOT_STATUSES))],
            'photoshoot_scheduled_at' => 'nullable|date',
            'photoshoot_studio'       => 'nullable|string|max:255',
            'photoshoot_notes'        => 'nullable|string|max:2000',
        ]);

        // A booking with no date is not a booking.
        if (in_array($data['photoshoot_status'], [ProductRequest::SHOOT_SCHEDULED, ProductRequest::SHOOT_IN_PROGRESS], true)
            && blank($data['photoshoot_scheduled_at'] ?? null)) {
            return back()->withErrors([
                'photoshoot_scheduled_at' => 'Give the shoot a date and time before marking it '
                    . strtolower(ProductRequest::SHOOT_STATUSES[$data['photoshoot_status']]) . '.',
            ]);
        }

        $was     = $productRequest->photoshoot_status;
        $wasDate = $productRequest->photoshoot_scheduled_at;

        $productRequest->update([
            'photoshoot_status'       => $data['photoshoot_status'],
            'photoshoot_scheduled_at' => ($data['photoshoot_scheduled_at'] ?? null) ?: null,
            'photoshoot_studio'       => ($data['photoshoot_studio'] ?? null) ?: null,
            'photoshoot_notes'        => ($data['photoshoot_notes'] ?? null) ?: null,
        ]);

        $this->workflow->log(
            request:     $productRequest,
            action:      'photoshoot',
            description: $was === $data['photoshoot_status']
                ? 'Photoshoot details updated'
                : 'Photoshoot ' . strtolower(ProductRequest::SHOOT_STATUSES[$data['photoshoot_status']]),
            actor:       $user,
            remarks:     $this->changeRemark($wasDate, $productRequest->photoshoot_scheduled_at, $data['photoshoot_studio'] ?? null),
        );

        // Keep the request's own stage in step, but only inside the photoshoot
        // band — a request sitting at QA has moved past this and must not be
        // dragged back by a calendar tidy-up.
        $moved = $this->workflow->syncStageWithShoot($productRequest, $user);

        return back()->with('success', "Photoshoot for {$productRequest->reference} is now "
            . strtolower(ProductRequest::SHOOT_STATUSES[$data['photoshoot_status']]) . '.'
            . ($moved ? " The request moved to {$productRequest->fresh()->statusLabel()}." : ''));
    }

    /** Only the coordinator books shoots; everyone else is reading the calendar. */
    private function canEdit(User $user): bool
    {
        return $user->is_super_admin || $user->hasPcrRole('photographer');
    }

    /**
     * Weeks of the month, each day carrying its shoots.
     *
     * Monday-first and always six full weeks, so the grid never reflows between
     * months — a calendar that changes height as you page through it is hard to
     * scan.
     *
     * @return array<int, array<int, array{date: Carbon, inMonth: bool, shoots: \Illuminate\Support\Collection}>>
     */
    private function calendar(Carbon $month, $shoots): array
    {
        $byDay = $shoots
            ->filter(fn ($r) => $r->photoshoot_scheduled_at !== null)
            ->groupBy(fn ($r) => $r->photoshoot_scheduled_at->toDateString());

        $cursor = $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $weeks  = [];

        for ($week = 0; $week < 6; $week++) {
            $days = [];

            for ($day = 0; $day < 7; $day++) {
                $days[] = [
                    'date'    => $cursor->copy(),
                    'inMonth' => $cursor->isSameMonth($month),
                    'shoots'  => $byDay->get($cursor->toDateString(), collect()),
                ];

                $cursor->addDay();
            }

            $weeks[] = $days;
        }

        return $weeks;
    }

    /** @return array<string, int> */
    private function stats($shoots): array
    {
        return [
            'pending'     => $shoots->where('photoshoot_status', ProductRequest::SHOOT_PENDING)->count(),
            'scheduled'   => $shoots->where('photoshoot_status', ProductRequest::SHOOT_SCHEDULED)->count(),
            'in_progress' => $shoots->where('photoshoot_status', ProductRequest::SHOOT_IN_PROGRESS)->count(),
            'completed'   => $shoots->where('photoshoot_status', ProductRequest::SHOOT_COMPLETED)->count(),
            'cancelled'   => $shoots->where('photoshoot_status', ProductRequest::SHOOT_CANCELLED)->count(),
            // The two numbers worth acting on today.
            'this_week'   => $shoots->filter(fn ($r) => $r->shootIsOpen()
                && $r->photoshoot_scheduled_at?->between(now()->startOfWeek(), now()->endOfWeek()))->count(),
            'overdue'     => $shoots->filter(fn ($r) => $r->isShootOverdue())->count(),
            'at_risk'     => $shoots->filter(fn ($r) => $r->shootIsOpen()
                && $r->online_launch_date !== null
                && $r->online_launch_date->diffInDays(now(), false) >= -7)->count(),
        ];
    }

    private function changeRemark(?Carbon $from, ?Carbon $to, ?string $studio): ?string
    {
        $parts = [];

        if ((string) $from?->toDateTimeString() !== (string) $to?->toDateTimeString()) {
            $parts[] = $to
                ? ($from ? "Moved from {$from->format('d M Y H:i')} to {$to->format('d M Y H:i')}"
                         : 'Booked for ' . $to->format('d M Y H:i'))
                : 'Date cleared';
        }

        if (filled($studio)) {
            $parts[] = "At {$studio}";
        }

        return $parts ? implode(' · ', $parts) : null;
    }
}
