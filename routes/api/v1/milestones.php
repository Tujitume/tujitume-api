<?php


/*
|--------------------------------------------------------------------------
| Milestones Routes (Business/Service Milestones)
|--------------------------------------------------------------------------
| Note: Program milestones are in programs.php
*/

use App\Http\Controllers\Audits\ProjectManagerAuditController;
use App\Service\Audits\ProjectManagerAudits;
use App\Http\Controllers\Business\InvestmentController;
use App\Http\Controllers\Business\MidMilestoneController;
use App\Http\Controllers\Business\MilestoneController;
use App\Http\Controllers\Business\MilestoneFinalApprovalController;
use App\Http\Controllers\Business\MilestonePMController;
use App\Http\Controllers\Business\PreReleaseRmepController;
use App\Http\Controllers\Business\ResolutionController;

Route::get('milestones', [MilestoneController::class, 'index']);

// Project Manager Audits: business and program audits together, by stage
Route::prefix('/pm-audits')->group(function () {
    Route::get('summary', [ProjectManagerAuditController::class, 'summary']);
    Route::get('project-managers', [ProjectManagerAuditController::class, 'candidates']);
    Route::get('{stage}', [ProjectManagerAuditController::class, 'index'])->whereIn('stage', ['pre_release', 'mid_milestone', 'final_approval']);
    Route::post('{source}/{id}/retry-fee', [ProjectManagerAuditController::class, 'retryFee'])->whereIn('source', ProjectManagerAudits::SOURCES)->whereNumber('id');
    Route::post('program/{verification}/respond', [ProjectManagerAuditController::class, 'programRespond']);
    Route::post('program/{verification}/report', [ProjectManagerAuditController::class, 'programReport']);
    Route::post('service/{milestone}/respond', [ProjectManagerAuditController::class, 'serviceRespond']);
    Route::post('service/{milestone}/report', [ProjectManagerAuditController::class, 'serviceReport']);
    // DECISION-9: a capital provider asked this PM to audit a capital milestone before releasing it
    Route::post('capital/{milestone}/respond', [ProjectManagerAuditController::class, 'capitalRespond'])->whereNumber('milestone');
    Route::post('capital/{milestone}/report', [ProjectManagerAuditController::class, 'capitalReport'])->whereNumber('milestone');
});

Route::prefix('/milestones')->group(function (){

    Route::get('{listing_id}', [MilestoneController::class, 'show']);

    Route::get('{id}/pre-release-requests', [PreReleaseRmepController::class, 'getPreReleaseRequests']);
    Route::get('{id}/investor/pre-release-requests', [PreReleaseRmepController::class, 'getInvestorPreReleaseRequests']);
    Route::get('{requestId}/pre-release-docs', [PreReleaseRmepController::class, 'getPreReleaseDocs']);

    Route::post('{id}/pre-release-requests', [PreReleaseRmepController::class, 'createPreReleaseRequest']);
    Route::post('{requestId}/pre-release-docs', [PreReleaseRmepController::class, 'uploadPreReleaseDocs']);
    Route::post('{requestId}/pre-release-votes', [PreReleaseRmepController::class, 'investorReview']);
    Route::post('set-status', [MilestoneController::class, 'mile_status']);

    //Mid-Milestone
    Route::get('{milestoneId}/mid-milestones', [MidMilestoneController::class, 'index']);
    Route::post('mid-milestones', [MidMilestoneController::class, 'store']);
    Route::post('{id}/mid-milestones-docs', [MidMilestoneController::class, 'uploadDocuments']); // BO uploads extra docs
    Route::post('mid-milestones/vote', [MidMilestoneController::class, 'vote_store']);
    Route::post('{id}/pm-review', [MidMilestoneController::class, 'pmAuditReview']); // PM performs audit -> pass/partial/fail

    //Mid-Milestone Pm Audit
    Route::get('audits/{type}', [MilestonePMController::class, 'audits']);
    Route::post('audit-request', [MilestonePMController::class, 'audit_request']);
    Route::post('audits/{auditId}/review', [MilestonePMController::class, 'audit_review']);
    Route::post('mid-milestones/audits', [MilestonePMController::class, 'audit_store']);

    //Candidates
    Route::get('mid-milestones/{id}/pm-candidates', [MilestonePMController::class, 'mid_pm_candidates']);
    Route::get('pre-release/{milestone_id}/pm-candidates', [MilestonePMController::class, 'pr_pm_candidates']);
    Route::get('final/{milestone_id}/pm-candidates', [MilestonePMController::class, 'final_pm_candidates']);

    //Votes
    Route::post('mid-milestones/pm-vote', [MilestonePMController::class, 'mid_pm_vote']);
    Route::post('pre-release/pm-vote', [MilestonePMController::class, 'pr_pm_vote']);
    Route::post('final/pm-vote', [MilestonePMController::class, 'final_pm_vote']);



    //Milestone - Investor
    Route::get('investor/investments', [InvestmentController::class, 'index']);
    // My Investments table: searched, filtered and paged on the server
    Route::get('investor/investments/list', [InvestmentController::class, 'list']);

    //rmpes
    Route::get('{milestoneId}/rmeps', [PreReleaseRmepController::class, 'rmeps']);
    Route::post('rmeps', [PreReleaseRmepController::class, 'rmep_store']);
    Route::post('rmeps/votes', [PreReleaseRmepController::class, 'vote_store']);

    //final approval
    Route::post('final-approval/document', [MilestoneFinalApprovalController::class, 'final_proof_store']);
    Route::get('{milestoneId}/final-approval/document', [MilestoneFinalApprovalController::class, 'final_proof_get']);
    Route::post('final-approval/votes', [MilestoneFinalApprovalController::class, 'final_approval_vote']);

    //DOCUMENTS DOWNLOAD also Handled from Frontend
    //Route::get('pre-release/{id}/download/document', [MilestoneController::class, 'pr_document_dld']);
    //Route::get('rmeps/{id}/download/document', [MilestoneController::class, 'rmep_document_dld']);
    //Route::get('{id}/download/document', [MilestoneController::class, 'documents']);

    Route::get('{id}/documents', [MilestoneController::class, 'documents']);

    // Non Compliance
    Route::get('{id}/non-compliances', [ResolutionController::class, 'non_compliances']);
    Route::post('non-compliance/votes', [ResolutionController::class, 'nc_vote_store']);
    Route::post('non-compliance/response-upload', [ResolutionController::class, 'nc_response_store']);
});
