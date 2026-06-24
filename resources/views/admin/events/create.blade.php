@extends('../admin.layout.mainlayout_admin')
@section('content')
<!-- Page Wrapper -->
<div class="container mt-5">
    <h2>Create New Setting</h2>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('settings.store') }}" method="POST">
        @csrf

        <div class="mb-3">
            <label for="key" class="form-label">Key</label>
            <input type="text" class="form-control" id="key" name="key" placeholder="Enter key" required>
        </div>

        <div class="mb-3">
            <label for="value" class="form-label">Value</label>
            <input type="text" class="form-control" id="value" name="value" placeholder="Enter value">
        </div>

        <button type="submit" class="btn btn-success">Save Setting</button>
        <a href="{{ route('settings.index') }}" class="btn btn-secondary">Cancel</a>
    </form>
</div>
	<script type="text/javascript">
		new DataTable('#example');
	</script>


@endsection
