@extends('admin.layout.mainlayout_admin')
@section('content')	
<!-- Main Wrapper -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.13.0/css/all.min.css">
<div class="main-wrapper login-body">
            <div class="login-wrapper">
            	<div class="container">
                	<div class="loginbox">
                    	<div class="login-left" style="background:darkseagreen;">
							<img class="img-fluid" src="../images/logo.png" alt="Logo">
                        </div>
                        <div class="login-right">
							<div class="login-right-wrap">
								<h1>Admin Login</h1>
								<p class="account-subtitle">Access to our dashboard</p>

								@if(Session::has('auth_failed'))
								<p class="bg-light account-subtitle text-warning">{{Session::get('auth_failed')}} </p>
								@endif
								
								<!-- Form -->
								<form action="{{route('adminLogin')}}"  method="post"> @csrf
									<div class="form-group">
										<input name="email" class="form-control" type="text" placeholder="Email">
									</div>
									<div class="form-group">
										<input name="password"class="form-control float-left" type="password" placeholder="Password" id="id_password">
										<i class="far fa-eye" id="togglePassword" style="margin-left: -30px; margin-top: 10px; cursor: pointer;"></i>
									</div>
									<div class="form-group">
										<button style="background: darkseagreen;" class="btn mt-5 btn-block font-weight-bold" type="submit">Login</button>
									</div>
								</form>
								<!-- /Form -->
								
								<div class="text-center forgotpass"><a href="{{route('forgot','email')}}">Forgot Password?</a></div>
								<div class="login-or">
									<span class="or-line"></span>
									
								</div>
								  
								
								{{--  
								<div class="text-center dont-have">Don’t have an account? <a href="register">Register</a></div>
								--}}
							</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
		<!-- /Main Wrapper -->

<script type="text/javascript">
  const togglePassword = document.querySelector('#togglePassword');
  const password = document.querySelector('#id_password');

  togglePassword.addEventListener('click', function (e) {
    // toggle the type attribute
    const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
    password.setAttribute('type', type);
    // toggle the eye slash icon
    this.classList.toggle('fa-eye-slash');
});
</script>

@endsection