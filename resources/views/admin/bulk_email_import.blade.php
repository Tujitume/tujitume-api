@extends('admin.layout.mainlayout_admin')
@section('content')
    <!-- Page Wrapper -->
    <div class="page-wrapper">
        <div class="content container-fluid">

            <!-- Page Header -->
            <div class="page-header">
                <div class="row">
                    <div class="col-sm-7 col-auto">
                        <h3 class="page-title">Bulk Email Import</h3>
                        <ul class="breadcrumb">
                            <li class="breadcrumb-item">Dashboard</li>
                            <li class="breadcrumb-item active">Bulk Email</li>
                        </ul>
                    </div>
                </div>
            </div>
            <!-- /Page Header -->

            {{-- Success Message --}}
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            {{-- Errors --}}
            @if(session('errors'))
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach(session('errors') as $line => $messages)
                            <li><strong>Line {{ $line + 1 }}:</strong> {{ implode(', ', $messages) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Upload Form --}}
            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-body">
                            <form action="{{ route('send-bulk-register-mails') }}" method="POST" enctype="multipart/form-data">
                                @csrf
                                <div class="form-group">
                                    <label for="file">Upload File (CSV or Excel)</label>
                                    <input type="file" name="file" id="file" accept=".csv,.xlsx,.xls" class="form-control" required>
                                    @error('file')
                                    <small class="text-danger">{{ $message }}</small>
                                    @enderror
                                </div>

                                <div class="form-group mt-3">
                                    <label for="subject">Email Subject (Optional)</label>
                                    <input type="text" name="subject" id="subject" class="form-control" value="{{ old('subject') }}">
                                    @error('subject')
                                    <small class="text-danger">{{ $message }}</small>
                                    @enderror
                                </div>


                                <button type="submit" style="background:#295c29;" class="text-white btn mt-4">Import & Send Emails</button>
                            </form>

                            @if(session('importErrors') && count(session('importErrors')) > 0)
                                <div class="alert alert-danger mt-3">
                                    <strong>Some emails could not be imported:</strong>
                                    <ul class="mb-0">
                                        @foreach(session('importErrors') as $line => $messages)
                                            <li>Line {{ $line + 1 }}: {{ implode(', ', $messages) }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
@endsection
