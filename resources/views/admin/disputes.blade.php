@extends('admin.layout.mainlayout_admin')
@section('content')
<!-- Page Wrapper -->
<div class="page-wrapper">
                <div class="content container-fluid">

					<!-- Page Header -->
					<div class="page-header">
						<div class="row">
							<div class="col-sm-7 col-auto">
								<h3 class="page-title">Disputes</h3>
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
													<th>ID</th>
													<th>Project Name</th>
													<th>Milestone</th>
													<th>Reason</th>
													<th>Details</th>
													<th>Time</th>
													<th>Action</th>

												</tr>

											</thead>

											<tbody>

											@if($disputes->count() > 0)

												@foreach($disputes as $l)
												<tr>

													<td>#JD-OO{{$l->id}}</td>
													<td>
													<p  type="button" class="border text-dark btn btn-light py-1 font-weight-bold small">
														{{$l->project_name}}
													</p>
													</td>

													<td>

														{{$l->mile_name}}

													</td>

													<td>

														{{$l->reason}}

													</td>
													<td>

														{{substr($l->details,0,30)}}...

													</td>

													<td>{{date('d M, h:ia',strtotime($l->created_at))}} </td>

													<td>

													<a  type="button" class="border text-dark btn btn-light py-1 font-weight-bold small" data-toggle="modal" data-target="#exampleModal{{$l->id}}">
														Details
													</a>

													<a onclick="confirm('are you sure?');" href="{{route('remove_dispute',$l->id )}}" type="button" class=" text-dark btn btn-outline-warning py-1 font-weight-bold small">
														Remove
													</a>
													</td>
												</tr>


						<!-- Modal -->
						<div class="modal fade" id="exampleModal{{$l->id}}" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
						  <div class="modal-dialog" role="document">
						    <div class="modal-content mx-auto text-center">
						      <div class="text-center modal-header">
						        <h5 class="modal-title  text-secondary mx-auto" id="exampleModalLabel">Info (#JD-OO{{$l->id}})</h5>
						        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
						          <span aria-hidden="true">&times;</span>
						        </button>
						      </div>
				      <div class="modal-body w-75 mx-auto">

				      	<div class="my-3 row w-75 mx-auto border">

				        	<div class="col-sm-6">
				        		<h5 class="w-75 text-center mt-2">Project Name</h5>
				        	</div>
				        	<div class="col-sm-6">
				        		<p class="font-weight-bold border shadow text-center mt-2
				        		">{{$l->project_name}}</p>
				        	</div>
				        </div>


				        <!-- GENERAL DETAILS -->
				        <div class="row">

				        	<div class="col-sm-6">
				        		<h6 class="w-75 text-left border pl-3 ">Dispute No.</h6>
				        	</div>
				        	<div class="col-sm-6">
				        		<p class="text-center">
							#JD-OO{{$l->id}}
							</p>
				        	</div>

				        	<div class="col-sm-6">
				        		<h6 class="w-75 text-left border pl-3 ">Milestone</h6>
				        	</div>
				        	<div class="col-sm-6">
				        		<p class="text-center">{{$l->mile_name}}</p>
				        	</div>

				        	<div class="col-sm-6">
				        		<h6 class="w-75 text-left border py-3 pl-3 ">Disputant</h6>
				        	</div>
				        	<div class="col-sm-6">
				        		<p class="text-center mb-1">{{$l->user?->fname ?? 'N/A'. ' '.$l->user?->lname ?? 'N/A' }}</p>
				        		<p class="font-weight-bold text-center">{{$l->user?->email ?? 'N/A' }}</p>
				        	</div>

				        	<div class="col-sm-11">

				        		<textarea cols="50" rows="3" class="rounded w-100 text-left" readonly
				        		style="border:none;" >{{$l->details}}</textarea>
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
