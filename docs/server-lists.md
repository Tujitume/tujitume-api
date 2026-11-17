# Server-side lists (search, filters, sort, pages, export)

Every big table should ask the server for one page instead of loading everything. The pieces:

| Piece | Where |
|---|---|
| Query helper (search, filters, sort, paging, export, filter counts) | `app/Support/ListQuery.php` |
| Response envelope and page info | `app/Support/ApiContract.php` |
| Reference endpoint | `app/Service/Account/TransactionList.php` (used by `AccountController::transactions`) |
| Tests | `tests/Feature/Support/ListQueryTest.php`, `TransactionListTest.php` |
| Frontend hook | `tujitume-frontend/src/components/dashboard/list/useServerList.js` |
| Export of all matching rows | `ExportButton` props `loadRows` + `total` |

## Request

`page`, `per_page` (max 100), `search`, `filters[key][]=value`, `filters[date]=from~to`, `sort=-column`, `export=1` (every match, capped at 5,000; the response says `export_truncated`).

Search: each word must appear in at least one searchable column (any case, any order); `%`, `_` and `!` typed by the person are plain text. Only columns an endpoint registers are ever used, so nothing from the client reaches SQL as an identifier.

## Response

```
{ success, message, data: { items: [...], pagination: {current_page, per_page, total, last_page, from, to},
                            facets: { key: [{value, count}] }, stats: {...}, export_truncated?: bool } }
```

An endpoint keeps its old response when none of page / per_page / search / filters / sort / export is sent, so pages that still read the full list keep working while they are migrated one by one.

## Migrating a list

1. Build the base query (with the access rules) and wrap it: `ListQuery::for($query, $request)->search([...])->filter(...)->dateRange(...)->sortable([...], default: '-date')`.
2. `[$rows, $page] = $list->fetch()`; add `$list->facets([...])`; add any totals the page's cards show (computed in SQL over the whole scope).
3. Derived states (a value the browser works out today) must be done in SQL with a `Closure` filter. Be careful with NULL: `NOT (x = ?)` is NULL, not true, when `x` is NULL (a bug of exactly this kind was caught in the transactions totals).
4. Frontend: `useServerList({ scope, path, filterGroups })`, build the filter options from `list.facets`, pass `meta / onPageChange / perPage / onPerPageChange / sortValue / onSortChange` to `Table`, and give `ExportButton` `loadRows={list.exportAll}` and `total={list.meta?.total}`.
5. A details pop-up opened from a link may not be on the current page: use `list.find(id)` (filter `id`).

## Program page reports (done)

`GET programs/{program}/overview/applications | wallet | activity` (`ProgramReportController`, owner and team only). Applications uses `ListQuery` (one table); the wallet ledger (disbursements + Business Coach fees) and the activity feed are assembled from several tables, so they use `CollectionList` (same request language, in memory, bounded by what one program can have). The wallet response also carries `wallet` and `analytics` next to the rows.

Known limit: a program wallet deposit is not itemised. `ProgramWallet.total_deposited` is a running total, and the `transactions` row written by a deposit does not say which program it was for (its `reference_id` is empty), so deposits cannot be listed per program without changing the payment code to store that link.

## Backlog (not migrated yet; each still loads its whole list)

Highest volume first: round applicants (needs its header counts, tabs and bulk actions moved to server totals first), orders, supplier directory, team members, Business Coaches, applications overview, awardees / deal room, notifications, businesses, services and bookings, events, meetings, site visits, milestones. Programs list is paged on the server already for some views (`ProgramController`), but without search/filters/export.

## Not verified

Run only against SQLite in tests. Search uses `LIKE '%word%'`, which cannot use an index; it is fine for tens of thousands of rows per scope. Beyond that, add a fulltext index or a search table for the biggest lists. Not run against MySQL or in a browser.
