<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Service\Misc\ErrorLogService;
use Illuminate\Support\Facades\Auth;

class InvestmentController extends Controller
{
    public function index(){
        try{
            if( !Auth::check() ){
                return response()->json(['message' => 'Unauthorized!' ],401);
            }
            $user = Auth::user();
            $user_id = $user->id;

            $pendingInvestments = BusinessBids::with('milestone.listing')
                ->where('investor_id', $user_id)->latest()->get();

            $investments = AcceptedBids::with('milestone.listing')
                ->where('investor_id', $user_id)->latest()->get();
            $awaiting_payment = $investments->where('status', 'awaiting_payment');

            return response()->json([
                'pending_investments' => $pendingInvestments,
                'investments' => $investments,
                'awaiting_payment' => $awaiting_payment,
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);

            return response()->json([
                //'message' => 'Something went wrong, please try again later.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * The investor's bids and investments as one list, searched, filtered, sorted and paged on the database (My
     * Investments used to load everything and filter in the browser).
     *   kind: bid (made, waiting for the owner) | investment (accepted)
     */
    public function list(\Illuminate\Http\Request $request)
    {
        $userId = Auth::id();
        $bids = \Illuminate\Support\Facades\DB::table('business_bids as b')
            ->join('listings as l', 'l.id', '=', 'b.business_id')
            ->where('b.investor_id', $userId)
            ->selectRaw("b.id, 'bid' as kind, b.business_id, l.name as business_name, l.image as business_image, b.ms_id, b.amount, b.representation, b.type, b.method, 'pending' as status, b.created_at");
        $accepted = \Illuminate\Support\Facades\DB::table('accepted_bids as a')
            ->join('listings as l', 'l.id', '=', 'a.business_id')
            ->where('a.investor_id', $userId)
            ->selectRaw("a.id, 'investment' as kind, a.business_id, l.name as business_name, l.image as business_image, a.ms_id, a.amount, a.representation, a.type, a.method, LOWER(a.status) as status, a.created_at");

        // A model named after the sub-select, so the list helper's columns read inv.created_at (not accepted_bids.…)
        $query = (new AcceptedBids())->setTable('inv')->newQuery()->fromSub($bids->unionAll($accepted), 'inv')->select('inv.*');

        $list = \App\Support\ListQuery::for($query, $request)
            ->search(['business_name'])
            ->options('status', 'status', label: 'investor:list.status')
            ->options('type', 'type', label: 'investor:list.type')
            ->options('kind', 'kind', label: 'investor:list.kind')
            ->numberRange('amount', 'amount', label: 'investor:list.amount')
            ->dateRange('created', 'created_at', label: 'investor:list.date')
            ->sortable(['created_at', 'amount', 'business_name'], default: '-created_at');

        [$rows, $page] = $list->fetch();
        $items = collect($rows)->map(function ($row) {
            $row = (array) ($row instanceof \Illuminate\Database\Eloquent\Model ? $row->getAttributes() : $row);
            $row['row_key'] = $row['kind'].'-'.$row['id']; // a bid and an investment can share an id
            $row['status'] = \App\Support\ApiContract::statusMeta('investment', $row['status'] ?? 'pending');
            return $row;
        })->values();

        return response()->json([
            'success' => true,
            'data' => array_filter([
                'items' => $items,
                // The same pagination shape as every other server list (current_page, from, to: the table's "Showing 1 – 1 of 1"); `page` kept for older readers
                'pagination' => $page ? ['current_page' => $page->currentPage(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(), 'from' => $page->firstItem(), 'to' => $page->lastItem()] : null,
                'facets' => $list->facets(['status', 'type', 'kind']),
                'filter_schema' => $list->filterSchema(),
            ], fn ($v) => $v !== null),
        ], 200);
    }
}
