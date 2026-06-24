@extends('admin.layout.mainlayout_admin')
@section('content')
    <div class="page-wrapper">
        <div class="content container-fluid">
            <!-- Page Header -->
            <div class="page-header">
                <div class="row">
                    <div class="col-sm-7 col-auto">
                        <h3 class="page-title">Grants</h3>
                        <ul class="breadcrumb">
                            <li class="breadcrumb-item"><a >Dashboard</a></li>
                            <li class="breadcrumb-item active">Grants</li>
                        </ul>
                    </div>
                </div>
            </div>
            <!-- /Page Header -->

            @if($grants->isEmpty())
                <p class="text-gray-500">No grants found.</p>
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
                                            <th class="px-4 py-2 border-b">Total Amount</th>
                                            <th class="px-4 py-2 border-b">Available</th>
                                            <th class="px-4 py-2 border-b">Per Business</th>
                                            <th class="px-4 py-2 border-b">Deadline</th>
                                            <th class="px-4 py-2 border-b">Visible</th>
                                            <th class="px-4 py-2 border-b">Created</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($grants as $grant)
                                            <tr class="text-left border-b hover:bg-gray-50">
                                                <td class="px-4 py-2">{{ $grant->id }}</td>
                                                <td class="px-4 py-2">{{ $grant->user?->name ?? 'N/A' }}</td>
                                                <td class="px-4 py-2">{{ $grant->grant_title }}</td>
                                                <td class="px-4 py-2">${{ number_format($grant->total_grant_amount,2) }}</td>
                                                <td class="px-4 py-2">
                                                    @if($grant->available_amount)
                                                        ${{ number_format($grant->available_amount,2) }}
                                                    @else
                                                        N/A
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2">${{ number_format($grant->funding_per_business,2) }}</td>
                                                <td class="px-4 py-2">{{ $grant->application_deadline }}</td>
                                                <td class="px-4 py-2">{{ $grant->visible ? 'Yes' : 'No' }}</td>
                                                <td class="px-4 py-2">{{ $grant->created_at->format('Y-m-d H:i') }}</td>
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
                    {{-- {{ $grants->links() }} --}}
                </div>
            @endif
        </div>
    </div>
@endsection
