@extends('../admin.layout.mainlayout_admin')

@section('content')
    <!-- Page Wrapper -->
    <div class="container mt-5">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Milestones Settings</h2>
        </div>

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4" id="milestoneTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="mid-tab" data-bs-toggle="tab"
                        data-bs-target="#mid" type="button" role="tab">
                    Mid Milestone Proof Criteria
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="final-tab" data-bs-toggle="tab"
                        data-bs-target="#final" type="button" role="tab">
                    Final Approval Criteria
                </button>
            </li>
        </ul>

        <div class="tab-content">

            <!-- ================= MID MILESTONE TAB ================= -->
            <div class="tab-pane fade show active" id="mid" role="tabpanel">

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5>Mid Milestone Proof Criteria</h5>
                    <a href=""
                       class="btn btn-success btn-sm">
                        Add New Criteria
                    </a>
                </div>

                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Required</th>
                        <th>Created At</th>
                        <th class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($midCriteria ?? collect() as $criteria)
                        <tr>
                            <td>{{ $criteria->id }}</td>
                            <td>{{ $criteria->title }}</td>
                            <td>
                            <span class="badge {{ $criteria->is_required ? 'bg-success' : 'bg-secondary' }}">
                                {{ $criteria->is_required ? 'Yes' : 'No' }}
                            </span>
                            </td>
                            <td>{{ $criteria->created_at->format('Y-m-d H:i') }}</td>
                            <td class="text-center">
                                <a href=""
                                   class="btn btn-primary btn-sm">Edit</a>

                                <form action=""
                                      method="POST" class="d-inline"
                                      onsubmit="return confirm('Are you sure?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center">No mid milestone criteria found.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>

            </div>

            <!-- ================= FINAL APPROVAL TAB ================= -->
            <div class="tab-pane fade" id="final" role="tabpanel">

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5>Final Approval Criteria</h5>
                    <a href=""
                       class="btn btn-success btn-sm">
                        Add New Criteria
                    </a>
                </div>

                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Required</th>
                        <th>Created At</th>
                        <th class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($finalCriteria ?? collect() as $criteria)
                        <tr>
                            <td>{{ $criteria->id }}</td>
                            <td>{{ $criteria->title }}</td>
                            <td>
                            <span class="badge {{ $criteria->is_required ? 'bg-success' : 'bg-secondary' }}">
                                {{ $criteria->is_required ? 'Yes' : 'No' }}
                            </span>
                            </td>
                            <td>{{ $criteria->created_at->format('Y-m-d H:i') }}</td>
                            <td class="text-center">
                                <a href=""
                                   class="btn btn-primary btn-sm">Edit</a>

                                <form action=""
                                      method="POST" class="d-inline"
                                      onsubmit="return confirm('Are you sure?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center">No final approval criteria found.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>

            </div>

        </div>
    </div>
@endsection
