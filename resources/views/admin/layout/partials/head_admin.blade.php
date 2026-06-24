<meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
        <meta name="csrf_token" content="{{ csrf_token() }}" />

        @if(!Route::is(['appointment-list','specialities','doctor-list','patient-list','reviews','transactions-list','settings','invoice-report','profile','login','register','forgot-password','lock-screen','error-404','error-500','blank-page','components','form-basic','form-inputs','form-horizontal','form-vertical','form-mask','form-validation','tables-basic','data-tables','invoice','calendar']))
        <title>Jitume - Dashboard</title>
        @endif
        @if(Route::is(['appointment-list']))
        <title>OPD Point - Appointment List Page</title>
        @endif
        @if(Route::is(['specialities']))
        <title>OPD Point - Specialities Page</title>
		@endif
        @if(Route::is(['doctor-list']))
        <title>OPD Point - Doctor List Page</title>
        @endif
        @if(Route::is(['patient-list']))
        <title>OPD Point - Patient List Page</title>
        @endif
        @if(Route::is(['reviews']))
        <title>OPD Point - Reviews Page</title>
        @endif
        @if(Route::is(['transactions-list']))
        <title>OPD Point - Transactions List Page</title>
        @endif
        @if(Route::is(['settings']))
        <title>OPD Point - Settings Page</title>
        @endif
        @if(Route::is(['invoice-report']))
        <title>OPD Point - Invoice Report Page</title>
        @endif
        @if(Route::is(['profile']))
        <title>OPD Point - Profile</title>
        @endif
        @if(Route::is(['login']))
        <title>Jitume - Login</title>
		@endif
        @if(Route::is(['register']))
        <title>OPD Point - Register</title>
        @endif
        @if(Route::is(['forgot-password']))
        <title>OPD Point - Forgot Password</title>
        @endif
        @if(Route::is(['lock-screen']))
        <title>OPD Point - Lock Screen</title>
        @endif
        @if(Route::is(['error-404']))
        <title>OPD Point - Error 404</title>
		@endif
        @if(Route::is(['error-500']))
        <title>OPD Point - Error 500</title>
		@endif
        @if(Route::is(['blank-page']))
        <title>OPD Point - Blank Page</title>
        @endif
        @if(Route::is(['components']))
        <title>OPD Point - Components</title>
        @endif
        @if(Route::is(['form-basic']))
        <title>OPD Point - Basic Inputs</title>
        @endif
        @if(Route::is(['form-inputs']))
        <title>OPD Point - Form Input Groups</title>
        @endif
        @if(Route::is(['form-horizontal']))
        <title>OPD Point - Horizontal Form</title>
        @endif
        @if(Route::is(['form-vertical']))
        <title>OPD Point - Vertical Form</title>
        @endif
        @if(Route::is(['form-mask']))
        <title>OPD Point - Form Mask</title>
        @endif
        @if(Route::is(['form-validation']))
        <title>OPD Point - Form Validation</title>
        @endif
        @if(Route::is(['tables-basic']))
        <title>OPD Point - Tables Basic</title>
        @endif
        @if(Route::is(['data-tables']))
        <title>OPD Point - Data Tables</title>
        @endif
        @if(Route::is(['invoice']))
        <title>OPD Point - Invoice</title>
        @endif
        @if(Route::is(['calendar']))
        <title>OPD Point - Calendar</title>
        @endif
		<!-- Favicon -->
        <link rel="shortcut icon" type="image/x-icon" href="{{asset('assets_admin/img/favicon.png')}}">
		
		<!-- Bootstrap CSS -->
        <link rel="stylesheet" href="{{asset('assets_admin/css/bootstrap.min.css')}}">
		
		<!-- Fontawesome CSS -->
        <link rel="stylesheet" href="{{asset('assets_admin/css/font-awesome.min.css')}}">
		
		<!-- Feathericon CSS -->
        <link rel="stylesheet" href="{{asset('assets_admin/css/feathericon.min.css')}}">
        <link rel="stylesheet" href="{{asset('assets_admin/plugins/morris/morris.css')}}">
        <!-- Select2 CSS -->
		<link rel="stylesheet" href="{{asset('assets_admin/css/select2.min.css')}}">
        	<!-- Datetimepicker CSS -->
		<link rel="stylesheet" href="{{asset('assets_admin/css/bootstrap-datetimepicker.min.css')}}">
		
		<!-- Full Calander CSS -->
        <link rel="stylesheet" href="{{asset('assets_admin/plugins/fullcalendar/fullcalendar.min.css')}}">
        <!-- Datatables CSS -->
		<link rel="stylesheet" href="{{asset('assets_admin/plugins/datatables/datatables.min.css')}}">
		
		<!-- <link rel="stylesheet" href="assets/plugins/morris/morris.css"> -->
		
		<!-- Main CSS -->
        <link rel="stylesheet" href="{{asset('assets_admin/css/style.css')}}">