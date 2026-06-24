<!DOCTYPE html>
<html lang="en">
  <head>
    @include('admin.layout.partials.head_admin')
  </head>

  <body>
{{--  @if(!Route::is(['login','register','forgot-password','lock-screen','error-404','error-500']))--}}

      @if(Auth::guard('admin')->check())
          @include('admin.layout.partials.header_admin')
          @include('admin.layout.partials.nav_admin')
      @endif

{{--@endif  --}}

@yield('content')
@include('admin.layout.partials.footer_admin-scripts')


 </body>
</html>
