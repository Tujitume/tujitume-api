@extends('admin.layout.mainlayout_admin')
@section('content')	
<!-- Page Wrapper -->
<div class="page-wrapper">
                <div class="content container-fluid">
				
					<!-- Page Header -->
					<div class="page-header">
						<div class="row">
							<div class="col-sm-7 col-auto">
								<h3 class="page-title">Doctors</h3>
								<ul class="breadcrumb">
									<li class="breadcrumb-item"><a href="index">Dashboard</a></li>
									<li class="breadcrumb-item active">Doctors</li>
								</ul>
							</div>
							<div class="col-sm-5 col">
								<a href="#add_doctor" data-toggle="modal" class="btn btn-primary float-right mt-2">Add</a>
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
												<tr> <th> See & edit slots </th>
													<th>Name</th>	
																								
													<th>Image</th>
													<th>Name</th>
													<th>Name</th>
													<th>Stage Name</th>
													<th>Artist Id</th>
													<th>Email</th>
													<th>Bio</th>
													<th>Approved</th>
												
													
													<th class="text-right">Action</th>
												</tr>
										
											</thead>
										
											<tbody>				
												@foreach($artists as $l)
												<tr>

												



													<td>
													<h2 class="table-avatar">
															<a  class="avatar avatar-lg mr-2"><img class="avatar-img rounded-circle" src="../assets_admin/img/doctors/{{$l->image}}" alt="User Image"></a>
															<a href="profile">{{$l->name}} </a>
														</h2>

													

														
												
													<td>{{$l->image}}</td>
													<td>{{$l->fname}}</td>
													<td>{{$l->lname}}</td>
													<td>{{$l->stage_name}}</td>
													<td>{{$l->art_id}}</td>
													<td>{{$l->email}}</td>
													<td>{{$l->bio}}</td>
													<td>{{$l->approved}}</td>

													
													<td class="text-right">
														<div class="actions">
															<a class="btn btn-sm bg-success-light" data-toggle="modal" href="#edit_specialities_details{{$l->id}}">
																<i class="fe fe-pencil"></i> Edit
															</a>
							<a onclick="return confirm('Are you sure...?') "  href="{{route('del_doctor',$l->id)}}" class="btn btn-sm bg-danger-light">
																<i class="fe fe-trash"></i> Delete
															</a>
														</div>
													</td>
												</tr>
												@endforeach
											
											


									</div>
								</div>
							</div>
						</div>			
					</div>
				</div>			
			</div>
			<!-- /Page Wrapper -->
			
			
			






@endsection