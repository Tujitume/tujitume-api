@extends('../admin.layout.mainlayout_admin')
@section('content')
<!-- Page Wrapper -->
<div class="container mt-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Settings</h2>
        <a href="{{ route('settings.create') }}" class="btn btn-success">Add New Setting</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <table class="table table-bordered table-striped">
        <thead class="table-dark">
        <tr>
            <th>ID</th>
            <th>Key</th>
            <th>Value</th>
            <th>Created At</th>
            <th>Updated At</th>
            <th class="text-center">Actions</th>
        </tr>
        </thead>
        <tbody>
        @forelse($settings as $setting)
            <tr>
                <td>{{ $setting->id }}</td>
                <td>{{ $setting->key }}</td>
                <td>{{ $setting->value }}</td>
                <td>{{ $setting->created_at->format('Y-m-d H:i') }}</td>
                <td>{{ $setting->updated_at->format('Y-m-d H:i') }}</td>
                <td class="text-center">
                    <a href="{{ route('settings.show', $setting->id) }}" class="btn btn-info btn-sm">View</a>
                    <a href="{{ route('settings.edit', $setting->id) }}" class="btn btn-primary btn-sm">Edit</a>
                    <form action="{{ route('settings.destroy', $setting->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure?');">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-danger btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="text-center">No settings found.</td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>
	<script type="text/javascript">
		new DataTable('#example');
	</script>


@endsection
