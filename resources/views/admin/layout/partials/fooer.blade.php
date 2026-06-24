
<footer class="footer">

				<!-- Footer Top -->
				<div class="footer-top">
					<div class="container-fluid">
						<div class="row">
							<div class="col-lg-3 col-md-6">

								

								<!-- Footer Widget -->
								<div class="footer-widget footer-menu">
									<h2 class="footer-title"> Patients</h2>
									<ul>
										<li><a href="@if(!Session::has('patient_email'))login @else all_doctors @endif"><i class="fas fa-angle-double-right"></i> Search
											for
											Doctors</a></li>

											@if(!Session::has('patient_email'))
										<li><a href="login"><i class="fas fa-angle-double-right"></i> Login</a></li>
										<li><a href="register"><i class="fas fa-angle-double-right"></i> Register</a></li>
										@else
											<li><a ><i class="fas fa-angle-double-right"></i> Logged in</a></li>
											@endif

										<li><a href="@if(!Session::has('patient_email'))login @else patient_dashboard @endif"><i class="fas fa-angle-double-right"></i> Patient Dashboard</a></li>
									</ul>
								</div>
								<!-- /Footer Widget -->

							</div>


							<div class="col-lg-3 col-md-6">

								

								<!-- Footer Widget -->
								<div class="footer-widget footer-menu">
									<h2 class="footer-title"> Find</h2>
									<ul>
										<li><a href="@if(!Session::has('patient_email'))login @else all_doctors @endif"><i class="fas fa-angle-double-right"></i> 
											
											Doctors</a></li>
										<li><a href="@if(!Session::has('patient_email'))login @else all_hospitals @endif"><i class="fas fa-angle-double-right"></i> Hospitals</a></li>
										<li><a href="@if(!Session::has('patient_email'))login @else all_clinics @endif"><i class="fas fa-angle-double-right"></i> Clinics</a></li>
										<li><a href="@if(!Session::has('patient_email'))login @else patient_dashboard @endif"><i class="fas fa-angle-double-right"></i> Appointments</a></li>
									</ul>
								</div>
								<!-- /Footer Widget -->

							</div>

						
							

							<div class="col-lg-3 col-md-6">

								<!-- Footer Widget -->
								<div class="footer-widget footer-contact">
									<h2 class="footer-title">Contact Us</h2>
									<div class="footer-contact-info">
										<div class="footer-address mb-0">
											<span><i class="fas fa-map-marker-alt"></i></span>
											<p> Gokarna Kandi Murshdabad,<br> Pin : 742136</p>
										</div>
										<p  class="mb-0">
											
											
											<i class="fas fa-phone-alt"></i>
											+91 7001314600<br>
											<i class="fas fa-phone-alt"></i>
											+91 8710060544
										
                              
                            
                              
                        
										</p>
										<p class="mb-0">
											<i class="fas fa-envelope"></i>
											opdpoint@gmail.com
										</p>
									</div>
								</div>
								<!-- /Footer Widget -->

							</div>
							
							
								<div class="col-lg-3 col-md-6">

								<!-- Footer Widget -->
								<div class="footer-widget footer-contact">
									<h2 class="footer-title ml-5">Others</h2>
									<div class="footer-contact-info">
										<div class="footer-address">
										<ul>	
											<li>
                              <a href="/PrivacyPolicy" class="text-light font-weight-bold mb-3 d-block">Privacy Policy</a>
                              </li>
                              
                              <li>
                              
                                <a href="/terms" class="text-light font-weight-bold">Terms and Conditions</a>
                              </li> </ul>
									</div>
								</div>
								<!-- /Footer Widget -->

							</div>

						</div>
					</div>
				</div>
				<!-- /Footer Top -->

				<!-- Footer Bottom -->
                <div class="footer-bottom">
					<div class="container-fluid">

						<!-- Copyright -->
						<div class="copyright">
							<div class="row">
								<div class="col-md-6 col-lg-6">
									<div class="copyright-text">
										<p class="mb-0"><a>Copyright © 2021 OpdPoint All Rights Reserved. </a></p>
									</div>
								</div>
								<div class="col-md-6 col-lg-6">

									<!-- Copyright Menu -->
									<div class="copyright-menu">
										<ul class="policy-menu">
											<li><a>OpdPoint - First.Best.Always</a>
											
										 <li>
                              
                                <a href=" https://policy.opdpoint.com/contact_us">Contact US</a>
                              </li>
										
										
                             
                              
                              
                              
                             
                              
                              
                              
                              
										</ul>
									</div>
									<!-- /Copyright Menu -->

								</div>
							</div>
						</div>
						<!-- /Copyright -->

					</div>
				</div>
				<!-- /Footer Bottom -->

			</footer>