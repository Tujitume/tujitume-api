@extends('admin.layout.mainlayout_admin')
@section('content')
<!-- Page Wrapper -->
<div class="page-wrapper">
    <div class="content container-fluid">

        <!-- Page Header -->
        <div class="page-header">
            <div class="row">
                <div class="col-sm-7 col-auto">
                    <h3 class="page-title">Users</h3>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index">Dashboard</a></li>
                        <li class="breadcrumb-item active">Transactions</li>
                    </ul>
                </div>

            </div>
        </div>
        <!-- /Page Header -->

    @if($transactions->isEmpty())
        <p class="text-gray-500">No transactions found.</p>
    @else
            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="table-responsive">

            <table class=" bg-white border border-gray-200 rounded-lg shadow-sm">
                <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-2 border-b">ID</th>
                    <th class="px-4 py-2 border-b">User</th>
                    <th class="px-4 py-2 border-b">Type</th>
                    <th class="px-4 py-2 border-b">Method</th>
                    <th class="px-4 py-2 border-b">Gross</th>
                    <th class="px-4 py-2 border-b">Fee</th>
                    <th class="px-4 py-2 border-b">Net</th>
                    <th class="px-4 py-2 border-b">Unsettled</th>
                    <th class="px-4 py-2 border-b">Status</th>
                    <th class="px-4 py-2 border-b">Reference</th>
                    <th class="px-4 py-2 border-b">Created By</th>
                    <th class="px-4 py-2 border-b">Date</th>
                </tr>
                </thead>
                <tbody>
                @foreach($transactions as $txn)
                    <tr class="text-left border-b hover:bg-gray-50">
                        <td class="px-4 py-2">{{ $txn->id }}</td>
                        <td class="px-4 py-2">{{ $txn->user?->fname.' '.$txn->user?->lname ?? 'N/A' }}</td>
                        <td class="px-4 py-2 capitalize">{{ str_replace('_', ' ', $txn->type) }}</td>
                        <td class="px-4 py-2">{{ $txn->method ?? 'N/A' }}</td>
                        <td class="px-4 py-2">${{ number_format($txn->gross_amount, 2) }}</td>
                        <td class="px-4 py-2">${{ number_format($txn->fee_amount, 2) }}</td>
                        <td class="px-4 py-2">${{ number_format($txn->net_amount, 2) }}</td>
                        <td class="px-4 py-2">${{ number_format($txn->unsettled_amount, 2) }}</td>
                        <td class="px-4 py-2 capitalize">{{ $txn->status }}</td>
                        <td class="px-4 py-2">{{ $txn->reference_id ?? '-' }}</td>
                        <td class="px-4 py-2">{{ $txn->created_by ?? 'System' }}</td>
                        <td class="px-4 py-2">{{ $txn->created_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
                        </div>
                    </div>
                </div>

        <div class="mt-4">
{{--            {{ $transactions->links() }}--}}
        </div>
    @endif
</div>
</div>

<script type="text/javascript">
    new DataTable('#example');
</script>

@endsection
