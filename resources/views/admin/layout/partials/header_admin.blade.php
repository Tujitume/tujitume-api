	<!-- Main Wrapper -->
	<div class="main-wrapper">

		<!-- Header -->
		<div class="header">

			<!-- Logo -->
			<div class="header-left">
				<a href="index_admin" class="logo">
					<img src="../../images/logo.png" alt="Logo">
				</a>
				<a href="index_admin" class="logo logo-small">
					<img src="../../images/logo2.png" alt="Logo" width="30" height="30">
				</a>
			</div>
			<!-- /Logo -->

			<a href="javascript:void(0);" id="toggle_btn">
				<i class="fe fe-text-align-left"></i>
			</a>

			<div class="top-nav-search">
				<form method="post" action="{{route('searchInAdmin')}}"> @csrf
					<input name="text" type="text" class="form-control" placeholder="Search here">
					<button class="btn" type="submit"><i class="fa fa-search"></i></button>
				</form>
			</div>

			<!-- Mobile Menu Toggle -->
			<a class="mobile_btn" id="mobile_btn">
				<i class="fa fa-bars"></i>
			</a>
			<!-- /Mobile Menu Toggle -->

			<!-- Header Right Menu -->
			<ul class="nav user-menu">



				<!-- User Menu -->
				<li class="nav-item dropdown has-arrow">
					<a href="#" class="dropdown-toggle nav-link" data-toggle="dropdown">
						<span class="user-img">Admin</span>
					</a>
					<div class="dropdown-menu">
						<div class="user-header">

							<div class="user-text">

								<p class="text-muted mb-0">ADMIN</p>
							</div>
						</div>

						<a href="{{route('logout')}}" class="dropdown-item" href="login">Logout</a>
					</div>
				</li>
				<!-- /User Menu -->

			</ul>
			<!-- /Header Right Menu -->

		</div>
		<!-- /Header -->

		<script>
// Set the inactivity timeout in milliseconds (15 minutes)
const inactivityTimeout = 60 * 60 * 1000;

// Function to handle user activity
function resetTimer() {
clearTimeout(timeoutId);
timeoutId = setTimeout(logoutUser, inactivityTimeout);
}

// Function to log out the user
function logoutUser() {
    alert('Session Lost!');
    // Get the CSRF token from a meta tag or a hidden input field
    const csrfToken = document.querySelector('meta[name="csrf_token"]').getAttribute('content');

    // Send a POST request to the /logout route with the CSRF token
    fetch('logout', {
        method: 'GET',
        headers: {
        'X-CSRF-TOKEN': csrfToken
        }
    })
    .then(response => {
        if (response.ok) {
        // Redirect to the login page or other appropriate action
        window.location.href = './login';
        } else {
        // Handle error, e.g., display an error message
        console.error('Logout failed:', response.statusText);
        }
    })
    .catch(error => {
        console.error('Logout failed:', error);
    });
}

// Event listeners for user activity
document.addEventListener('mousemove', resetTimer);
document.addEventListener('keydown', resetTimer);
document.addEventListener('click', resetTimer);
document.addEventListener('scroll', resetTimer);

// Initial timeout
let timeoutId = setTimeout(logoutUser, inactivityTimeout);
</script>
