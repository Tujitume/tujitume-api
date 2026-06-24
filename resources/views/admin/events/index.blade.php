@extends('admin.layout.mainlayout_admin')
@section('content')
    <div class="page-wrapper">
        <div class="content container-fluid">
            <!-- Page Header -->
            <div class="page-header">
                <div class="row">
                    <div class="col-sm-7 col-auto">
                        <h3 class="page-title">Events</h3>
                        <ul class="breadcrumb">
                            <li class="breadcrumb-item"><a>Dashboard</a></li>
                            <li class="breadcrumb-item active">Events</li>
                        </ul>
                    </div>
                </div>
            </div>
            <!-- /Page Header -->

            @if($events->isEmpty())
                <p class="text-gray-500">No events found.</p>
            @else
                <div class="row">
                    <div class="col-sm-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="bg-white border border-gray-200 rounded-lg shadow-sm w-full">
                                        <thead class="bg-gray-100">
                                        <tr>
                                            <th class="px-4 py-2 border-b">Image</th>
                                            <th class="px-4 py-2 border-b">ID</th>
                                            <th class="px-4 py-2 border-b">Event Name</th>
                                            <th class="px-4 py-2 border-b">Type</th>
                                            <th class="px-4 py-2 border-b">Sector</th>
                                            <th class="px-4 py-2 border-b">Organizer</th>
                                            <th class="px-4 py-2 border-b">Start Date</th>
                                            <th class="px-4 py-2 border-b">End Date</th>
                                            <th class="px-4 py-2 border-b">Timezone</th>
                                            <th class="px-4 py-2 border-b">Location</th>
                                            <th class="px-4 py-2 border-b">Cost</th>
                                            <th class="px-4 py-2 border-b">Active</th>
                                            <th class="px-4 py-2 border-b text-center">Actions</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($events as $event)
                                            <tr class="text-left border-b hover:bg-gray-50">
                                                <td class="px-4 py-2 text-center">
                                                    @if($event->cover_image)
                                                        <img src="{{config('api_base_url'). '/' . $event->cover_image }}" alt="Event Image" class="rounded" style="width: 60px; height: 60px; object-fit: cover;">
                                                    @else
                                                        <span class="text-gray-400 text-sm">No image</span>
                                                    @endif
                                                </td>

                                                <td class="px-4 py-2">{{ $event->id }}</td>
                                                <td class="px-4 py-2">{{ $event->event_name }}</td>
                                                <td class="px-4 py-2">{{ ucfirst($event->event_type) }}</td>
                                                <td class="px-4 py-2">{{ $event->sector }}</td>
                                                <td class="px-4 py-2">{{ $event->user?->name ?? 'N/A' }}</td>
                                                <td class="px-4 py-2">{{ \Carbon\Carbon::parse($event->start_date)->format('Y-m-d H:i') }}</td>
                                                <td class="px-4 py-2">{{ \Carbon\Carbon::parse($event->end_date)->format('Y-m-d H:i') }}</td>
                                                <td class="px-4 py-2">{{ $event->timezone }}</td>
                                                <td class="px-4 py-2">
                                                    @if($event->location_type === 'in_person')
                                                        {{ $event->venue ? $event->venue . ', ' : '' }}
                                                        {{ $event->address ? $event->address . ', ' : '' }}
                                                        {{ $event->city ? $event->city . ', ' : '' }}
                                                        {{ $event->country ?? '' }}
                                                    @elseif($event->location_type === 'virtual')
                                                        <a href="{{ $event->virtual_url }}" target="_blank" class="text-blue-600 underline">Join Link</a>
                                                    @else
                                                        N/A
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2">
                                                    {{ $event->cost_type }}
                                                    @if($event->price)
                                                        <br>{{ $event->currency }} {{ number_format($event->price, 2) }}
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2">
                                                    @if($event->active)
                                                        <span class="badge bg-success text-white px-2 py-1 rounded">Active</span>
                                                    @else
                                                        <span class="badge bg-secondary text-white px-2 py-1 rounded">Inactive</span>
                                                    @endif
                                                </td> &nbsp;
                                                <td class="px-4 ml-2 py-2 text-center">
                                                    <div class="d-flex justify-content-center gap-2">
                                                        <a href="{{ route('events.show', $event->id) }}" class="mx-2 btn btn-info btn-sm px-3">View</a>
                                                        <form action="{{ route('events.destroy', $event->id) }}" method="POST" onsubmit="return confirm('Are you sure you want to delete this event?');" class="d-inline">
                                                            @csrf
                                                            @method('DELETE')
                                                            @if($event->active)
                                                                <button href="{{route('admin.events.toggle', $event->id) }}" class="btn btn-outline-success btn-sm px-3">Deactivate</button>
                                                            @else
                                                                <button href="{{route('admin.events.toggle', $event->id) }}" class="btn btn-outline-warning btn-sm px-3">Activate</button>
                                                            @endif
                                                        </form>
                                                    </div>
                                                </td>
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
                    {{-- {{ $events->links() }} --}}
                </div>
            @endif
        </div>
    </div>
@endsection
