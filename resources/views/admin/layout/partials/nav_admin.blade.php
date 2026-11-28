<!-- Sidebar -->
<div class="sidebar" id="sidebar">
                <div class="sidebar-inner slimscroll">
					<div id="sidebar-menu" class="sidebar-menu">
						<ul>
							<li class="menu-title">
								<span>Main</span>
							</li>
							<li class="{{ Request::is('admin/dashboard') ? 'active' : '' }}">
								<a href="{{ url('admin/dashboard') }}"><i class="fe fe-home"></i> <span>Dashboard</span></a>
							</li>
							<!-- <li class="{{ Request::is('admin/artists') ? 'active' : '' }}">
								<a href="{{ url('admin/artists') }}"><i class="fe fe-layout"></i> <span>Artists</span></a>
							</li> -->

							<li  class="{{ Request::is('admin/users') ? 'active' : '' }}">
								<a href="{{ url('admin/users') }}"><i class="fe fe-user-plus"></i> <span>Users</span></a>
							</li>

							<li class="{{ Request::is('admin/kyc*') ? 'active' : '' }}">
								<a href="{{ route('admin.kyc.index') }}"><i class="fe fe-check-circle"></i> <span>KYC</span></a>
							</li>

							<li class="{{ Request::is('admin/demo-requests*') ? 'active' : '' }}">
								<a href="{{ route('admin.demo_requests.index') }}"><i class="fe fe-calendar"></i> <span>Demo Requests</span>
									@php($pendingDemoRequests = \App\Models\DemoRequest::where('status', 'pending')->count())
									@if($pendingDemoRequests > 0)<span class="badge badge-pill badge-warning ml-1">{{ $pendingDemoRequests }}</span>@endif
								</a>
							</li>

							<li class="{{ Request::is('admin/amari*') ? 'active' : '' }}">
								<a href="{{ route('admin.amari.index') }}"><i class="fe fe-activity"></i> <span>Amari usage</span>
									@php($amariLimitHits = \Illuminate\Support\Facades\Schema::hasTable('amari_usage_logs') ? \App\Models\Amari\AmariUsageLog::where('status', 'limited')->where('created_at', '>=', \App\Service\AI\AmariUsage::startOfToday())->distinct()->count(\Illuminate\Support\Facades\DB::raw('coalesce(cast(user_id as char), guest_key)')) : 0)
									@if($amariLimitHits > 0)<span class="badge badge-pill badge-danger ml-1" title="Reached the Amari limit today">{{ $amariLimitHits }}</span>@endif
								</a>
							</li>

							<li  class="{{ Request::is('admin/listings-active') ? 'active' : '' }}">
								<a href="{{ url('admin/listings-active') }}"><i style="font-size: 18px;" class="fa fa-list fa-1x"></i> <span>Active Businesses</span></a>
							</li>

							<li  class="{{ Request::is('admin/services-active') ? 'active' : '' }}">
								<a href="{{ url('admin/services-active') }}"><img style="width: 18px;" src="{{ asset('images/admin/active.png') }}" /> <span>Active Services</span></a>
							</li>

							<li  class="{{ Request::is('admin/prospects') ? 'active' : '' }}">
								<a href="{{ url('admin/prospects') }}"><img style="width: 18px;" src="{{ asset('images/admin/prospects.png') }}" /> <span>Prospects</span></a>
							</li>

							<li class="{{ Request::is('admin/non-compliance*') ? 'active' : '' }}">
								<a href="{{ route('admin.non_compliance.index') }}"><i class="fe fe-warning"></i> <span>Non-compliance</span></a>
							</li>
							<li  class="{{ Request::is('admin/disputes') ? 'active' : '' }}">
								<a href="{{ url('admin/disputes') }}"><img style="width: 18px;" src="{{ asset('images/admin/disputes.png') }}" /> <span>Disputes</span></a>
							</li>

							<li  class="{{ Request::is('admin/reports') ? 'active' : '' }}">
								<a href="{{ url('admin/reports') }}"><img style="width: 18px;" src="{{ asset('images/admin/reports.png') }}" /> <span>Reports</span></a>
							</li>

                            <li  class="{{ Request::is('admin/transactions') ? 'active' : '' }}">
                                <a href="{{ url('admin/transactions') }}" class=""> <i class="bi bi-gear-fill"></i> Transactions</a>
                            </li>

                            <li  class="{{ Request::is('admin/programs') ? 'active' : '' }}">
                                <a href="{{ url('admin/programs') }}" class=""> <i class="bi bi-gear-fill"></i> Programs</a>
                            </li>

                            <li  class="{{ Request::is('admin/capitals') ? 'active' : '' }}">
                                <a href="{{ url('admin/capitals') }}" class=""> <i class="bi bi-gear-fill"></i> Capitals</a>
                            </li>

                            <li  class="{{ Request::is('admin/events') ? 'active' : '' }}">
                                <a href="{{ url('admin/events') }}" class=""> <i class="bi bi-gear-fill"></i> Events</a>
                            </li>

                            <li  class="{{ Request::is('admin/milestones') ? 'active' : '' }}">
                                <a href="{{ url('admin/milestones') }}" class=""> <i class="bi bi-gear-fill"></i> Milestones</a>
                            </li>

                            <li  class="{{ Request::is('admin/settings') ? 'active' : '' }}">
                                <a href="{{ url('admin/settings') }}" class=""> <i class="bi bi-gear-fill"></i> Settings</a>
                            </li>

                            <li  class="{{ Request::is('admin/bulk-emails') ? 'active' : '' }}">
                                <a href="{{ url('admin/bulk-emails') }}" class=""> <i class="bi bi-gear-fill"></i> Bulk Emails</a>
                            </li>

                            <li  class="{{ Request::is('admin/error-logs') ? 'active' : '' }}">
                                <a href="{{ url('admin/error-logs') }}" class=""> <i class="bi bi-gear-fill"></i> Error Logs</a>
                            </li>


						</ul>
					</div>
                </div>
            </div>
			<!-- /Sidebar -->
