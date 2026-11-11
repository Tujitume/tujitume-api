<?php

namespace App\Service\Pending;

use App\Models\Auth\User;
use Illuminate\Support\Facades\DB;

/**
 * One kind of waiting work for the Pending drawer. To add a case for a new feature, add ONE class that extends this
 * under Scenarios/<Area>/: it is found automatically, nothing else to register.
 *
 *   key()        a unique name, "area.what"
 *   userTypes()  the user_type_id values it applies to (null = every account)
 *   collect()    returns a list of groups built with $this->group(...) and $this->item(...)
 *
 * The rule: a scenario is something only THIS person can move on (a status waiting for their action), never something
 * merely interesting. Each item names the page that fixes it in `link` (the notification-link format, for example
 * "dashboard.programOrg.applicationsMultiRound::id=3::roundId=5"), so the person lands exactly there.
 * Item `type` values ("area.what") can be translated through the app's pendingType_* keys; without a translation
 * the English title you give is shown.
 */
abstract class Scenario
{
    public const MAX_ITEMS = 15;

    /** The groups the drawer knows (and translates): programs, review, manage, investments, services, account. */
    public const GROUPS = ['programs', 'review', 'manage', 'investments', 'services', 'account'];

    protected User $user;
    protected int $type;
    /** The organization owner for program-team members; the person themselves otherwise. */
    protected int $ownerId;

    abstract public function key(): string;

    /** @return int[]|null */
    abstract public function userTypes(): ?array;

    /** @return array<int, array> a list of groups: ['group' => ..., 'count' => ..., 'items' => [...]] */
    abstract public function collect(): array;

    public function appliesTo(int $userType): bool
    {
        $types = $this->userTypes();

        return $types === null || in_array($userType, $types, true);
    }

    public function forUser(User $user): static
    {
        $this->user = $user;
        $this->type = (int) $user->user_type_id;
        $this->ownerId = $this->type === 4 ? (int) $user->organizationOwnerId() : (int) $user->id;

        return $this;
    }

    protected function group(string $group, $query, \Closure $map, ?int $count = null): array
    {
        $count ??= (int) (clone $query)->count();
        $items = $count > 0 ? (clone $query)->limit(self::MAX_ITEMS)->get()->map($map)->all() : [];

        return ['group' => $group, 'count' => $count, 'items' => $items];
    }

    protected function item(string $type, string $title, ?string $subtitle, string $link, $id, ?string $due = null, ?int $count = null): array
    {
        return array_filter([
            'id' => $type.':'.$id, 'type' => $type, 'title' => $title, 'subtitle' => $subtitle, 'link' => $link,
            'due' => $due, 'count' => $count,
        ], fn ($v) => $v !== null);
    }

    protected function home(): string
    {
        return match ($this->type) {
            1 => 'dashboard.entrepreneur', 2 => 'dashboard.investor', 3 => 'dashboard.serviceProvider', 4 => 'dashboard.programOrg',
            5 => 'dashboard.capitalOrg', default => 'dashboard.reviewerGrantOrg',
        };
    }

    /** The Pending drawer itself, on one of its tabs (the drawer opens from the address on any page). */
    protected function drawer(string $tab): string
    {
        return $this->home().'?requests=1&requestsTab='.$tab;
    }

    protected function date($value): ?string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->toDateString() : null;
    }

    protected function ownerPrograms()
    {
        return DB::table('programs')->where('user_id', $this->ownerId);
    }

    protected function applications()
    {
        return DB::table('program_applications as a')->join('programs as p', 'p.id', '=', 'a.program_id')->where('a.user_id', $this->user->id);
    }

    protected function bookingsLink(bool $asProvider): string
    {
        return match (true) {
            $this->type === 3 => $asProvider ? 'dashboard.serviceProvider.serviceBookings' : 'dashboard.serviceProvider.myBookings',
            $this->type === 2 => 'dashboard.investor.bookings', $this->type === 4 => 'dashboard.grantOrg.bookings', $this->type === 5 => 'dashboard.capitalOrg.bookings',
            default => $this->home(),
        };
    }
}
