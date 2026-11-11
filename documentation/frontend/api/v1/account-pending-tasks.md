# Pending tasks

One request that finds everything waiting for the signed-in person. The top-bar badge and the **All** tab of the Pending drawer read it.

`GET /api/v1/pending-tasks` (any signed-in user). Cached for 20 seconds per user. `?fresh=1` skips the cache (use it right after the person acts).

```json
{ "data": {
  "total": 4,
  "groups": [
    { "key": "manage", "count": 3, "items": [
      { "id": "owner.mprv:12", "type": "owner.mprv", "title": "Milestone to review",
        "subtitle": "Maize Co · Buy mill", "link": "dashboard.programOrg.programDealroomDetail::61::mprv#milestone-12",
        "due": "2026-12-01", "count": 2 } ] }
  ],
  "generated_at": "2026-11-11T13:00:00+00:00" } }
```

| Field | Notes |
|---|---|
| `groups[].key` | `programs`, `review`, `manage`, `investments`, `services`, `account`. The drawer translates them; an unknown key is shown as it is |
| `items[].type` | `area.what`. The app may translate it (`pendingType_area_what`); without a translation `title` (English) is shown |
| `items[].link` | The notification-link format (`routeName::id::step::key=value?query#section`). Resolve it with `resolveNotificationLink(link, userTypeId)` |
| `items[].count` | Present when one row stands for several things (for example 4 applications to score in one round) |
| `groups[].count` | Real number of waiting things, even when only the first items are listed |

A link that carries `requests=1&requestsTab=review|siteVisit|checkpoint` opens that tab of the drawer itself (those requests are answered in the drawer).

## What it covers, by account

Run `php artisan pending:scenarios` for the live list. At the time of writing:

* **Everyone:** verification not finished or rejected; team invitation waiting.
* **Business coaches, service providers, team members:** review requests, applications to score, site visit requests, assigned monitoring work, changes requested on delivered work. Service providers also: project-manager audits.
* **Program owners and their team:** work delivered for approval, fee proposals, milestones and completion proofs to review, funding plans to review, verification agreements to review, monitoring submissions to verify, applications waiting for a decision, rounds to score or finalize, AI reviews needing attention, failed payments, wallet that cannot cover reviewers.
* **Entrepreneurs:** draft applications, next round to submit, funding plan and milestones to fix, verification agreements to answer, monitoring to complete; their businesses: bids to answer, terms to accept, milestones at risk or overdue, mid-milestone documents to upload.
* **Investors:** accepted bids to pay, pre-release votes, mid-milestone votes, final proof votes, RMEP votes.
* **Capital organizations:** startup pitches to review, counter terms to answer.
* **Anyone who books or provides services:** bookings to confirm, pay or release, price offers and counter offers.

## Adding a case for a new feature

The system finds scenarios by itself. Add one file and the case appears for the right people, with no other change.

1. Create `app/Service/Pending/Scenarios/<Area>/<Name>.php` extending `App\Service\Pending\Scenario`.
2. Implement three methods:
   * `key()` : a unique `area.what`.
   * `userTypes()` : the `user_type_id` values it applies to, or `null` for everyone (1 entrepreneur, 2 investor, 3 service provider, 4 program org, 5 capital org, 6/7 reviewers).
   * `collect()` : return `[$this->group('<group>', $query, fn ($row) => $this->item('<type>', '<English title>', '<subtitle>', '<link>', $row->id, '<due date or null>'))]`.
3. The rule: it must be something **only this person** can move forward (a status waiting for their action), and the `link` must open the page where they do it.
4. Optional: add `pendingType_<area>_<what>` to the frontend's `app.json` (English and Swahili). Without it the English title is shown.

`tests/Feature/Pending/PendingScenariosTest.php` checks every scenario automatically: unique keys, valid user types, known groups, and that none throws for any account type (a failing scenario is otherwise skipped silently, so that test is what catches mistakes). Write one test for your case in the same style.

A scenario that throws is logged (`Pending scenario failed`) and skipped; the other scenarios still answer.
