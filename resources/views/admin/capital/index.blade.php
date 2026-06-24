@extends('admin.layout.mainlayout_admin')
@section('content')
    <div class="page-wrapper">
        <div class="content container-fluid">
            <!-- Page Header -->
            <div class="page-header">
                <div class="row">
                    <div class="col-sm-7 col-auto">
                        <h3 class="page-title">Capital Offers</h3>
                        <ul class="breadcrumb">
                            <li class="breadcrumb-item"><a >Dashboard</a></li>
                            <li class="breadcrumb-item active">Capital Offers</li>
                        </ul>
                    </div>
                </div>
            </div>
            <!-- /Page Header -->

            @if($capitals->isEmpty())
                <p class="text-gray-500">No capital offers found.</p>
            @else
                <div class="row">
                    <div class="col-sm-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="bg-white border border-gray-200 rounded-lg shadow-sm w-full">
                                        <thead class="bg-gray-100">
                                        <tr>
                                            <th class="px-4 py-2 border-b">ID</th>
                                            <th class="px-4 py-2 border-b">User</th>
                                            <th class="px-4 py-2 border-b">Title</th>
                                            <th class="px-4 py-2 border-b">Total Capital</th>
                                            <th class="px-4 py-2 border-b">Available</th>
                                            <th class="px-4 py-2 border-b">Per Startup</th>
                                            <th class="px-4 py-2 border-b">Visible</th>
                                            <th class="px-4 py-2 border-b">Created</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($capitals as $offer)
                                            <tr class="text-left border-b hover:bg-gray-50">
                                                <td class="px-4 py-2">{{ $offer->id }}</td>
                                                <td class="px-4 py-2">{{ $offer->user?->name ?? 'N/A' }}</td>
                                                <td class="px-4 py-2">{{ $offer->offer_title }}</td>
                                                <td class="px-4 py-2">${{ number_format($offer->total_capital_available,2) }}</td>
                                                <td class="px-4 py-2">
                                                    @if($offer->available_amount)
                                                        ${{ number_format($offer->available_amount,2) }}
                                                    @else
                                                        N/A
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2">${{ number_format($offer->per_startup_allocation,2) }}</td>
                                                <td class="px-4 py-2">{{ $offer->visible ? 'Yes' : 'No' }}</td>
                                                <td class="px-4 py-2">{{ $offer->created_at->format('Y-m-d H:i') }}</td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    {{-- {{ $capitalOffers->links() }} --}}
                </div>
            @endif
        </div>
    </div>
@endsection
