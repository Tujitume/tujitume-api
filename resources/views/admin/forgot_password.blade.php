

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="" />
        <meta name="author" content="" />
        <title>Forgot Password</title>
        <link href="css/styles.css" rel="stylesheet" />
	<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css">
    </head>
	
	
    <body class="bg-dark">
	
	
	
	
	
	
	
	
	
	
        <div id="layoutAuthentication">
            <div id="layoutAuthentication_content">
                <main>
                    <div class="container">
                        <div class="row justify-content-center">
				
						
                            <div class="col-lg-5">
						
						@if($remail=='email')	 
						<form action="{{route('send_reset_email')}}"  method="post"> @csrf
						
						 <div class="mt-5"><input class="form-control w-75 d-inline " name="email"  type="email" required="" placeholder="Enter Email"  /> 
						 <button type="submit" name="email_ck" data-target="#forgotten" data-toggle="collapse" class=" collapsed mb-1 btn btn-danger font-italic px-3"> Send </button></div>
						
						
						</form>
							
							@else
                                <div  class="  card shadow-lg border-0 rounded-lg mt-5">
                                    <div class="card-header bg-dark text-light">Email Matched ! <h3 class="text-center text-light font-weight-light my-4">Reset Password</h3></div>
                                    <div class="card-body">
									
                        <form action="{{route('reset',$remail)}}" class="" method="post"> @csrf
                                            <div class="form-group"><label class="small mb-1" for="inputEmailAddress">New Password</label>
											
											<input class="  form-control my-2" name="password" id="inputEmailAddress" type="password" placeholder="Enter password"
											  /></div>
											
													@if(session()->has('wrong_pwd'))
													{{Session::get('wrong_pwd')}}
													@endif

																						
																						
                                            <div class="form-group"><label class="small mb-1" for="inputPassword">Confirm Password</label>
											
											<input class=" form-control  my-2 " name="c_password" id="inputPassword" type="password" placeholder="Enter password"
                             							/></div>
											
											<span class="text-danger font-weight-bold"><?php  if(isset($_SESSION['unmatched'])) {echo $_SESSION['unmatched']; $_SESSION['unmatched']="";}?></span>
                                            
											<div class="form-group">
                                                <div class="custom-control custom-checkbox">
												
                                            
											
											</div>
                                            <div class="form-group d-flex align-items-center justify-content-between mt-4 mb-0">
											
											<input type="submit" class=" font-italic font-weight-bold btn btn-dark text-danger" href="" name="reset" value="Reset" /></div>
                                        </form>
										

                                    </div> @endif
                                    <div class="card-footer text-center">

                                    
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>
            </div>
            <div id="layoutAuthentication_footer">
                <footer class="py-4 bg-light mt-auto fixed-bottom mt-4">
                    <div class="container-fluid">
                        <div class="d-flex align-items-center justify-content-between small">
                            <div class="text-muted">Copyright &copy; Bubbledots 2021</div>
                            <div>
                                <a href="#">Privacy Policy</a>
                                &middot;
                                <a href="#">Terms &amp; Conditions</a>
                            </div>
                        </div>
                    </div>
                </footer>
            </div>
        </div>
        <script src="https://code.jquery.com/jquery-3.4.1.slim.min.js" integrity="sha384-J6qa4849blE2+poT4WnyKhv5vZF5SrPo0iEjwBvKU7imGFAV0wwj1yYfoRSJoZ+n" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js" integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js" integrity="sha384-wfSDF2E50Y2D1uUdj0O3uMBJnjuUD4Ih7YwaYd1iqfktj0Uod8GCExl3Og8ifwB6" crossorigin="anonymous"></script>
	
		<script src="js/scripts.js"></script>
    </body>
</html>
