@extends('admin.layout.mainlayout_admin')
@section('content')	
<!-- Page Wrapper -->
<div class="page-wrapper">
                <div class="content container-fluid">
				
					<!-- Page Header -->
					<div class="page-header">
						<div class="row">
							<div class="col-sm-7 col-auto">
								<h3 class="page-title">Prospects</h3>
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
													<th>Email</th>
													<th>Action</th>
													
												</tr>
										
											</thead>
										
											<tbody>	

											@if($prospects->count() > 0)

												@foreach($prospects as $l)
												<tr>
													<td>
													<p  type="button" class="border text-dark btn btn-light py-1 font-weight-bold small">
														{{$l->email}}
													</p>
													</td>

													<td>
													<a  type="button" class=" text-dark btn btn-outline-warning py-1 font-weight-bold small">
														Unsubscribe
													</a>
													</td>		
												</tr>
						
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