<?php error_reporting(0);?>
<!-- Loader -->
@if(Route::is(['map-grid','map-list']))
<div id="loader">
		<div class="loader">
			<span></span>
			<span></span>
		</div>
	</div>
	@endif

	@php 
 $user=DB::table('patients')->where('email',Session::get('patient_email'))->first();

 @endphp


	<!-- /Loader  -->
<div class="main-wrapper">
<!-- Header -->
<header class="header">
				<nav class="navbar navbar-expand-lg header-nav">
					<div class="navbar-header">
						<a id="mobile_btn" href="javascript:void(0);">
							<span class="bar-icon">
								<span></span>
								<span></span>
								<span></span>
							</span>
						</a>
						<a href="index" class="navbar-brand logo">
							<img src="assets/img/logo.png" class="img-fluid" alt="Logo">
						</a>
					</div>
					<div class="main-menu-wrapper">
						<div class="menu-header">
							<a href="index" class="menu-logo">
								<img src="assets/img/logo.png" class="img-fluid" alt="Logo">
							</a>
							<a id="menu_close" class="menu-close" href="javascript:void(0);">
								<i class="fas fa-times"></i>
							</a>
						</div>

                     @if(!Session::has('patient_email'))
						<ul class="main-nav">
							<li class="{{ Request::is('index') ? 'active' : '' }}">
								<a href="index">Home</a>
							</li>
							<li class="has-submenu <?php if($page=="review" || $page=="register" || $page=="doctor-dashboard" || $page=="appointments" || $page=="schedule-timings" || $page=="my-patients" || $page=="patient-profile" || $page=="chat-doctor" || $page=="invoices" || $page=="doctor-profile-settings") { echo 'active'; } ?>">
								<a href="">Search <i class="fas fa-chevron-down"></i></a>
								<ul class="submenu">
									
									<li class="<?php if($page=="schedule-timings") { echo 'active'; } ?>"><a href="all_doctors">Doctors</a></li>
									<li class="<?php if($page=="my-patients") { echo 'active'; } ?>"><a href="all_hospitals">Hospitals</a></li>
									<li class="<?php if($page=="my-patients") { echo 'active'; } ?>"><a href="all_clinics">Clinics</a></li>
									
								</ul>
							</li>	

							<li class="has-submenu <?php if($page=="map-grid" || $page=="map-list" || $page=="search1" || $page=="doctor-profile" || $page=="booking" || $page=="checkout" || $page=="booking-success" || $page=="patient-dashboard" || $page=="favourites" || $page=="chat" || $page=="profile-settings" || $page=="change-password") { echo 'active'; } ?>">
								<a href="login">Doctor Appointments <i class="fas "></i></a>
								{{-- <ul class="submenu">
									
									<li class="<?php if($page=="search1") { echo 'active'; } ?>"><a href="search">Search Doctor</a></li>
									<li class="<?php if($page=="doctor-profile") { echo 'active'; } ?>"><a href="doctor-profile">Doctor Profile</a></li>
									<li class="<?php if($page=="booking") { echo 'active'; } ?>"><a href="booking">Booking</a></li>
									<li class="<?php if($page=="checkout") { echo 'active'; } ?>"><a href="checkout">Checkout</a></li>
									
								</ul> --}}
							</li>













						<li >
								<a href="login" target="_blank">Download Invoice</a>
	
							</li>

						<li style="margin-right:200px" class="{{ Request::is('aboutus') ? 'active' : '' }}">
								<a href="aboutus">About Us</a>
							</li>
							
							
									<!-- profile -->
							<li class="nav-item contact-item mt-3">
							<div class="header-contact-img">
								<i class="far fa-hospital"></i>							
							</div>
							<div class="header-contact-detail">
								<p class="contact-header">Contact</p>
								<p class="contact-info-header"> 7001314600</p>
							</div>
							 @if(!Session::has('patient_email')) 

							
							<li class="  my-3">
							<button class="btn btn-outline-dark d-inline-block p-0" href="login"> <a class="p-3  w-100" href="login" >LogIn | SignUp</a></button>
							</li>
							
						</li>
						@else
						<li class="nav-item dropdown has-arrow logged-item">
							<a href="#" class="dropdown-toggle nav-link" data-toggle="dropdown">
								<span class="user-img">
									<img class="rounded-circle" 
									src="@if(strpos($user->image, 'https')!==false) {{$user->image}} @else assets_admin/img/patients/{{$user->image}} @endif"
									 width="31" alt="">
								</span>
							</a>
							<div class="dropdown-menu dropdown-menu-right">
								<div class="user-header">  
									
									
									<div class="avatar avatar-sm">
										<img src="@if(strpos($user->image, 'https')!==false) {{$user->image}} @else assets_admin/img/patients/{{$user->image}} @endif" alt="User Image" class="avatar-img rounded-circle">
									</div>
									<div class="user-text">
										<h6>{{$user->name}}</h6> 
										
									</div>

								</div>
								<a class="dropdown-item" href="patient_dashboard">Dashboard</a>

								
												
												<a class="dropdown-item pl-2" href="{{route('profile_settings')}}" >
												<button style="background: none;border: none;font-size: 15px;"class="  " type="submit">Profile Settings </button> </a>
												

								<a class="dropdown-item" href="patient_logout">Logout</a>
							</div>
						</li>
						
						@endif
						<!-- profile -->
						
						
						
						
						</ul>

						@else

						<ul class="main-nav">
							<li class="{{ Request::is('index') ? 'active' : '' }}">
								<a href="index">Home</a>
							</li>
							<li class="has-submenu <?php if($page=="review" || $page=="register" || $page=="doctor-dashboard" || $page=="appointments" || $page=="schedule-timings" || $page=="my-patients" || $page=="patient-profile" || $page=="chat-doctor" || $page=="invoices" || $page=="doctor-profile-settings") { echo 'active'; } ?>">
								<a href="">Find <i class="fas fa-chevron-down"></i></a>
								<ul class="submenu">
									
									<li class="<?php if($page=="appointments") { echo 'active'; } ?>"><a href="patient_dashboard">Appointments</a></li>
									<li class="<?php if($page=="schedule-timings") { echo 'active'; } ?>"><a href="all_doctors">Doctors</a></li>
									<li class="<?php if($page=="my-patients") { echo 'active'; } ?>"><a href="all_hospitals">Hospitals</a></li>
									<li class="<?php if($page=="my-patients") { echo 'active'; } ?>"><a href="all_clinics">Clinics</a></li>

									
								</ul>
							</li>		
							<li class="has-submenu <?php if($page=="map-grid" || $page=="map-list" || $page=="search1" || $page=="doctor-profile" || $page=="booking" || $page=="checkout" || $page=="booking-success" || $page=="patient-dashboard" || $page=="favourites" || $page=="chat" || $page=="profile-settings" || $page=="change-password") { echo 'active'; } ?>">
								<a href="patient_dashboard">Doctor Appointments <i class="fas "></i></a>
								{{-- <ul class="submenu">
									
									<li class="<?php if($page=="search1") { echo 'active'; } ?>"><a href="search">Search Doctor</a></li>
									<li class="<?php if($page=="doctor-profile") { echo 'active'; } ?>"><a href="doctor-profile">Doctor Profile</a></li>
									<li class="<?php if($page=="booking") { echo 'active'; } ?>"><a href="booking">Booking</a></li>
									<li class="<?php if($page=="checkout") { echo 'active'; } ?>"><a href="checkout">Checkout</a></li>
									
								</ul> --}}
							</li>











						<li  >
								<a href="{{route('dld_all')}}" target="_blank">Invoices</a>
							</li>

								<li style="margin-right:200px" class="{{ Request::is('aboutus') ? 'active' : '' }}">
								<a href="aboutus">About Us</a>
							</li>
							
							
							
							<!-- profile -->
							<li class="nav-item contact-item  mt-3">
							<div class="header-contact-img">
								<i class="far fa-hospital"></i>							
							</div>
							<div class="header-contact-detail">
								<p class="contact-header">Contact</p>
								<p class="contact-info-header"> 7001314600</p>
							</div>
							 @if(!Session::has('patient_email')) 

							
							<li class="nav-item">
							<a class="nav-link header-login" href="login">LogIn | SignUp</a>
							</li>
							
						</li>
						@else
						<li class="nav-item dropdown has-arrow logged-item">
							<a href="#" class="dropdown-toggle nav-link" data-toggle="dropdown">
								<span class="user-img">
									<img class="rounded-circle" 
									src="@if(strpos($user->image, 'https')!==false) {{$user->image}} @else assets_admin/img/patients/{{$user->image}} @endif"
									 width="31" alt="">
								</span>
							</a>
							<div class="dropdown-menu dropdown-menu-right">
								<div class="user-header">  
									
									
									<div class="avatar avatar-sm">
										<img src="@if(strpos($user->image, 'https')!==false) {{$user->image}} @else assets_admin/img/patients/{{$user->image}} @endif" alt="User Image" class="avatar-img rounded-circle">
									</div>
									<div class="user-text">
										<h6>{{$user->name}}</h6> 
										
									</div>

								</div>
								<a class="dropdown-item" href="patient_dashboard">Dashboard</a>

								
												
												<a class="dropdown-item pl-2" href="{{route('profile_settings')}}" >
												<button style="background: none;border: none;font-size: 15px;"class="  " type="submit">Profile Settings </button> </a>
												

								<a class="dropdown-item" href="patient_logout">Logout</a>
							</div>
						</li>
						
						@endif
						<!-- profile -->
						
						
						
						</ul>

						@endif



					</div>
					
					
					
			{{--		<div class="">
					<ul class="nav header-navbar-rht">
					
						

					 @if(!Route::is(['page','blank-page','term-condition','privacy-policy','blog-details','blog-grid','blog-list','forgot-password','register','login','calendar','invoice-view','components','change-password','video-call','doctor-register','voice-call','favourites','map-grid','profile-settings','map-list','search','patient-dashboard','doctor-profile','booking','checkout','booking-success']))
						<!-- User Menu -->
						<li class="nav-item dropdown has-arrow logged-item">
							<a href="#" class="dropdown-toggle nav-link" data-toggle="dropdown">
								<span class="user-img">
									<img class="rounded-circle" src="assets/img/doctors/doctor-thumb-02.jpg" width="31" alt="Darren Elder">
								</span>
							</a>
							<div class="dropdown-menu dropdown-menu-right">
								<div class="user-header">
									<div class="avatar avatar-sm">
										<img src="assets/img/doctors/doctor-thumb-02.jpg" alt="User Image" class="avatar-img rounded-circle">
									</div>
									<div class="user-text">
										<h6>Darren Elder</h6>
										<p class="text-muted mb-0">Doctor</p>
									</div>
								</div>
								<a class="dropdown-item" href="doctor-dashboard">Dashboard</a>
								<a class="dropdown-item" href="doctor-profile-settings">Profile Settings</a>
								<a class="dropdown-item" href="login">Logout</a>
							</div>
						</li>
						<!-- /User Menu -->
						@endif 
						
					</ul> </div> --}}	
				</nav>
			</header>
			<!-- /Header -->