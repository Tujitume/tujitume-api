@extends('admin.layout.mainlayout_admin')
@section('content')
<!-- Page Wrapper -->
<div class="page-wrapper">
     <div class="content container-fluid">
         <h2 class="mb-3">Error Logs</h2>

         {{-- Filter & Search --}}
         <form method="GET" class="mb-4">
             <div class="row g-2">
                 <div class="col-md-3">
                     <select name="type" class="form-control">
                         <option value="">All Types</option>
                         <option value="Exception" {{ request('type') == 'Exception' ? 'selected' : '' }}>Exception</option>
                         <option value="Validation" {{ request('type') == 'Validation' ? 'selected' : '' }}>Validation</option>
                         <option value="Mail" {{ request('type') == 'Mail' ? 'selected' : '' }}>Mail</option>
                     </select>
                 </div>

                 <div class="col-md-4">
                     <input type="text"
                            name="search"
                            value="{{ request('search') }}"
                            class="form-control"
                            placeholder="Search message / file / type...">
                 </div>

                 <div class="col-md-2">
                     <button class="btn btn-primary w-100">Filter</button>
                 </div>
             </div>
         </form>

         {{-- Logs Table --}}
         <div class="card shadow-sm">
             <div class="card-body p-0">
                 <table class="table table-striped table-bordered mb-0">
                     <thead>
                     <tr>
                         <th>ID</th>
                         <th>Type</th>
                         <th>Message</th>
                         <th>Location</th>
                         <th>User</th>
                         <th>URL</th>
                         <th>Date</th>
                         <th>Trace</th>
                     </tr>
                     </thead>

                     <tbody>
                     @forelse($logs as $log)
                         <tr>
                             <td>{{ $log->id }}</td>
                             <td><span class="badge bg-danger">{{ $log->type }}</span></td>

                             <td style="max-width: 300px; white-space: normal;">
                                 {{-- Str::limit($log->message, 120) --}}
                                 {{ $log->message }}
                             </td>

                             <td>
                                 @if($log->file)
                                     <small>{{ $log->file }}:{{ $log->line }}</small>
                                 @endif
                             </td>

                             <td>{{ $log->user_id ?? 'Guest' }}</td>

                             <td style="max-width: 220px; word-break: break-all;">
                                 {{ $log->url }}
                             </td>

                             <td>
                                 {{ $log->created_at->diffForHumans() }}
                             </td>

                             <td>
                                 <!-- Button triggers modal -->
                                 <button class="btn btn-sm btn-dark"
                                         data-bs-toggle="modal"
                                         data-bs-target="#traceModal{{ $log->id }}">
                                     View
                                 </button>

                                 <!-- Modal -->
                                 <div class="modal fade" id="traceModal{{ $log->id }}" tabindex="-1">
                                     <div class="modal-dialog modal-xl modal-dialog-scrollable">
                                         <div class="modal-content">
                                             <div class="modal-header">
                                                 <h5 class="modal-title">Error Trace #{{ $log->id }}</h5>
                                                 <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                             </div>
                                             <div class="modal-body">

                                                 <h6>Message</h6>
                                                 <pre class="bg-light p-3">{{ $log->message }}</pre>

                                                 @if($log->trace)
                                                     <h6>Stack Trace</h6>
                                                     <pre class="bg-dark text-white p-3" style="white-space: pre-wrap;">
{{ $log->trace }}
                                                </pre>
                                                 @endif

                                                 @if($log->context)
                                                     <h6>Context</h6>
                                                     <pre class="bg-secondary text-white p-3">
{{ json_encode(json_decode($log->context), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}
                                                </pre>
                                                 @endif
                                             </div>

                                             <div class="modal-footer">
                                                 <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                             </div>
                                         </div>
                                     </div>
                                 </div>

                             </td>
                         </tr>
                     @empty
                         <tr>
                             <td colspan="8" class="text-center p-4">No logs found.</td>
                         </tr>
                     @endforelse
                     </tbody>
                 </table>
             </div>
         </div>

         <div class="mt-3">
             {{ $logs->links() }}
         </div>

    </div>
</div>

<script type="text/javascript">
    new DataTable('#example');
</script>

@endsection
