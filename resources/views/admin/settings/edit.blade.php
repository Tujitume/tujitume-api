@extends('../admin.layout.mainlayout_admin')
@section('content')
<!-- Page Wrapper -->
<div class="container">
    <h1 class="mb-4">Edit Setting</h1>

    <div class="card shadow-sm">
        <div class="card-body">
            <form action="{{ route('settings.update', $setting->id) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="mb-3">
                    <label for="key" class="form-label">Key</label>
                    <input type="text" class="form-control" id="key" name="key"
                           value="{{ old('key', $setting->key) }}" readonly>
                </div>

                <div class="mb-3">
                    <label for="value" class="form-label">Value</label>
                    <input type="text" class="form-control" id="value" name="value"
                           value="{{ old('value', $setting->value) }}">
                </div>

                <button type="submit" class="btn btn-success">Update</button>
                <a href="{{ route('settings.index') }}" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
</div>

	<script type="text/javascript">
		new DataTable('#example');
	</script>




@endsection
