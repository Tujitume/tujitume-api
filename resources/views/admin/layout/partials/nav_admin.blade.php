<!-- Sidebar -->
<div class="sidebar" id="sidebar">
                <div class="sidebar-inner slimscroll">
					<div id="sidebar-menu" class="sidebar-menu">
						<ul>
							<li class="menu-title">
								<span>Main</span>
							</li>
							<li class="{{ Request::is('admin/dashboard') ? 'active' : '' }}">
								<a href="dashboard"><i class="fe fe-home"></i> <span>Dashboard</span></a>
							</li>
							<!-- <li class="{{ Request::is('admin/artists') ? 'active' : '' }}">
								<a href="artists"><i class="fe fe-layout"></i> <span>Artists</span></a>
							</li> -->

							<li  class="{{ Request::is('admin/users') ? 'active' : '' }}">
								<a href="users"><i class="fe fe-user-plus"></i> <span>Users</span></a>
							</li>

							<li  class="{{ Request::is('admin/listings-active') ? 'active' : '' }}">
								<a href="listings-active"><i style="font-size: 18px;" class="fa fa-list fa-1x"></i> <span>Active Businesses</span></a>
							</li>

							<li  class="{{ Request::is('admin/services-active') ? 'active' : '' }}">
								<a href="services-active"><img style="width: 18px;" src="../images/admin/active.png" /> <span>Active Services</span></a>
							</li>

							<li  class="{{ Request::is('admin/prospects') ? 'active' : '' }}">
								<a href="prospects"><img style="width: 18px;" src="../images/admin/prospects.png" /> <span>Prospects</span></a>
							</li>

							<li  class="{{ Request::is('admin/disputes') ? 'active' : '' }}">
								<a href="disputes"><img style="width: 18px;" src="../images/admin/disputes.png" /> <span>Disputes</span></a>
							</li>

							<li  class="{{ Request::is('admin/reports') ? 'active' : '' }}">
								<a href="reports"><img style="width: 18px;" src="../images/admin/reports.png" /> <span>Reports</span></a>
							</li>

                            <li  class="{{ Request::is('admin/transactions') ? 'active' : '' }}">
                                <a href="transactions" class=""> <i class="bi bi-gear-fill"></i> Transactions</a>
                            </li>

                            <li  class="{{ Request::is('admin/grants') ? 'active' : '' }}">
                                <a href="grants" class=""> <i class="bi bi-gear-fill"></i> Grants</a>
                            </li>

                            <li  class="{{ Request::is('admin/capitals') ? 'active' : '' }}">
                                <a href="capitals" class=""> <i class="bi bi-gear-fill"></i> Capitals</a>
                            </li>

                            <li  class="{{ Request::is('admin/events') ? 'active' : '' }}">
                                <a href="events" class=""> <i class="bi bi-gear-fill"></i> Events</a>
                            </li>

                            <li  class="{{ Request::is('admin/milestones') ? 'active' : '' }}">
                                <a href="milestones" class=""> <i class="bi bi-gear-fill"></i> Milestones</a>
                            </li>

                            <li  class="{{ Request::is('admin/settings') ? 'active' : '' }}">
                                <a href="settings" class=""> <i class="bi bi-gear-fill"></i> Settings</a>
                            </li>

                            <li  class="{{ Request::is('admin/bulk-emails') ? 'active' : '' }}">
                                <a href="bulk-emails" class=""> <i class="bi bi-gear-fill"></i> Bulk Emails</a>
                            </li>

                            <li  class="{{ Request::is('admin/error-logs') ? 'active' : '' }}">
                                <a href="error-logs" class=""> <i class="bi bi-gear-fill"></i> Error Logs</a>
                            </li>


						</ul>
					</div>
                </div>
            </div>
			<!-- /Sidebar -->
