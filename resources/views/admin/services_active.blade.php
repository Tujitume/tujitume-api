@extends('admin.layout.mainlayout_admin')
@section('content')
<!-- Page Wrapper -->
<div class="page-wrapper">
                <div class="content container-fluid">

					<!-- Page Header -->
					<div class="page-header">
						<div class="row">
							<div class="col-sm-7 col-auto">
								<h3 class="page-title">Active Services</h3>
								<ul class="breadcrumb">
									<li class="breadcrumb-item"><a href="index">Dashboard</a></li>
									<li class="breadcrumb-item active">Users</li>
								</ul>
							</div>

						</div>
					</div>
					<!-- /Page Header -->
					<div class="row">
						<div class="col-sm-12">
							<div class="card">
								<div class="card-body">
									<div class="table-responsive">


										<table class="datatable table table-hover table-center mb-0">
											<thead>
												<tr>
													<!-- <th>Image</th> -->
													<th>User(Owner)</th>
													<th class="">Service</th>
													<th>Amount</th>
													<th>Email</th>

												</tr>

											</thead>

											<tbody>

											@if($count > 0)

												@foreach($businesses as $key=>$l)
												<tr>
													<td>
													<button  type="button" class="border text-dark btn btn-light py-1 font-weight-bold small" data-toggle="modal" data-target="#exampleModal{{$l[0]->id}}">
														{{$l[0]->fname.' '.$l[0]->lname}}
													</button>
													</td>

													<td style="color:#267c6f;font-weight: bold;">{{$l[0]->name}}</td>

													<td style="color:#267c6f;font-weight: bold;">${{$l[0]->price}}</td>


													<td>{{$l[0]->email}}</td>



												</tr>

						<!-- Modal -->
						<div class="modal fade" id="exampleModal{{$l[0]->id}}" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
						  <div class="modal-dialog" role="document">
						    <div class="modal-content mx-auto text-center">
						      <div class="text-center modal-header">
						        <h5 class="modal-title  text-secondary mx-auto" id="exampleModalLabel">User Info ({{$l[0]->fname.' '.$l[0]->lname}})</h5>
						        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
						          <span aria-hidden="true">&times;</span>
						        </button>
						      </div>
				      <div class="modal-body w-75 mx-auto">

				      	<div class="my-3 row w-75 mx-auto border">

				        	<div class="col-sm-6">
				        		<h5 class="w-75 text-center mt-2">User Type</h5>
				        	</div>
				        	<div class="col-sm-6">
				        		<p class="font-weight-bold border shadow text-center mt-2
				        		">
                                    @if($l[0]->user_type_id == 1)
                                        Investor

                                    @else
                                        Service

                                    @endif</p>
				        	</div>
				        </div>


				        <!-- GENERAL DETAILS -->
				        <div class="row">

				        	<div class="col-sm-6">
				        		<h6 class="w-75 text-left border pl-3 ">DOB</h6>
				        	</div>
				        	<div class="col-sm-6">
				        		<p class="text-center">@if($l[0]->dob == '')
							N/A
							@else
							{{$l[0]->dob}}
							@endif</p>
				        	</div>

				        	<div class="col-sm-6">
				        		<h6 class="w-75 text-left border pl-3 ">Gender</h6>
				        	</div>
				        	<div class="col-sm-6">
				        		<p class="text-center">@if($l[0]->gender == '')
							N/A
							@else
							{{$l[0]->gender}}
							@endif</p>
				        	</div>

				        	<div class="col-sm-6">
				        		<h6 class="w-75 text-left border pl-3 ">Email</h6>
				        	</div>
				        	<div class="col-sm-6">
				        		<p class="text-center">{{$l[0]->email}}</p>
				        	</div>

				        	<div class="col-sm-6">
				        		<h6 class="w-75 text-left border pl-3 ">Id No</h6>
				        	</div>
				        	<div class="col-sm-6">
				        		<p class="text-center">@if($l[0]->id_no == '')
							N/A
							@else
							{{$l[0]->id_no}}
							@endif</p>
				        	</div>

				        	<div class="col-sm-6">
				        		<h6 class="w-75 text-left border pl-3">Tax Pin</h6>
				        	</div>
				        	<div class="col-sm-6">
				        		<p class="text-center">@if($l[0]->tax_pin == '')
							N/A
							@else
							{{$l[0]->tax_pin}}
							@endif</p>
				        	</div>

				        	<div class="col-sm-6">
				        		<h6 class="w-75 text-left border pl-3">Investment Range</h6>
				        	</div>
				        	<div class="col-sm-6">
				        		<p class="text-center">@if($l[0]->inv_range == '')
							N/A
							@else
							{{$l[0]->inv_range}}
							@endif</p>
				        	</div>



				        </div>
				      </div>
						      <div class="modal-footer">
						        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>

						      </div>
						    </div>
						  </div>
						</div>
						<!-- Modal -->

												@endforeach
												@endif
											</tbody>
										</table>




									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<!-- /Page Wrapper -->



	<script type="text/javascript">
		new DataTable('#example');
	</script>











@endsection
