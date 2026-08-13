<?php

use Illuminate\Support\Facades\Route;
/*
|--------------------------------------------------------------------------
| Grant Routes
|--------------------------------------------------------------------------
| All grant-related routes
| Already wrapped in: middleware(['auth:sanctum', 'extend-token', 'throttle:api'])
*/

use App\Http\Controllers\CheckoutStripeController;
use App\Http\Controllers\Grant\ApplicationRoundProgressController;
use App\Http\Controllers\Grant\GrantApplicationController;
use App\Http\Controllers\Grant\GrantController;
use App\Http\Controllers\Grant\GrantDealRoomController;
use App\Http\Controllers\Grant\GrantDisbursementController;
use App\Http\Controllers\Grant\GrantEmailTemplateController;
use App\Http\Controllers\Grant\GrantMilestoneController;
use App\Http\Controllers\Grant\GrantServiceController;
use App\Http\Controllers\Grant\GrantSupplierController;
use App\Http\Controllers\Grant\GrantWalletController;
use App\Http\Controllers\Grant\MEAnalyticsController;
use App\Http\Controllers\Grant\MEController;
use App\Http\Controllers\Grant\MilestonePreAgreementController;
use App\Http\Controllers\Grant\MilestoneVerificationController;
use App\Http\Controllers\Grant\Rounds\ApplicationScoreController;
use App\Http\Controllers\Grant\Rounds\GrantRoundController;
use App\Http\Controllers\Grant\Rounds\RoundDocumentController;
use App\Http\Controllers\Grant\Rounds\RoundQuestionController;
use App\Http\Controllers\Grant\Rounds\RoundReviewerController;
use App\Http\Controllers\Grant\SupplierDirectoryController;
use App\Http\Controllers\Misc\AnalyticsController;
use App\Http\Controllers\Misc\MatchController;

Route::prefix('/grant')->middleware(['grant'])->group(function(){
    //Grant
    Route::get('grants', [GrantController::class, 'index']);
    Route::get('public-grants', [GrantController::class, 'public_grants']);
    Route::get('get_grant/{id}', [GrantController::class, 'get_grant']);

    Route::get('{grant_id}/applications', [GrantApplicationController::class, 'index']);
    Route::get('{grant_id}/applications/awarded', [GrantApplicationController::class, 'awarded']);
    Route::get('applications/{id}', [GrantApplicationController::class, 'show']);
    Route::get('my-applications', [GrantApplicationController::class, 'myPitches']);
    Route::get('visibility/{grant_id}', [GrantController::class, 'visibility']);
    Route::delete('delete-grant/{id}', [GrantController::class, 'destroy']);

    Route::get('fund-release-request/{pitch_id}', [GrantApplicationController::class, 'fund_request']);
    Route::get('analytics', [AnalyticsController::class, 'index']);
    Route::get('grantWritingServices', [GrantServiceController::class, 'grantWritingServices']);
    Route::get('store-watchlist/{pitch_id}', [GrantController::class, 'store_watchlist']);
    Route::get('get-watchlist', [GrantController::class, 'get_watchlist']);

    Route::post('create-grant', [GrantController::class, 'store']);
    Route::post('grants/{grant}', [GrantController::class, 'update']);
    Route::post('/grants/{grant}/duplicate', [GrantController::class, 'duplicate']);

    // for SME
    Route::get('/{grant_id}/sme/applications', [GrantApplicationController::class, 'application_info']);
    Route::get('/my-applications/awarded', [GrantApplicationController::class, 'smeAwarded']);


    Route::post('/{grant}/applications',[GrantApplicationController::class,'store_application']);
    Route::post('applications/{pitch}/accept', [GrantApplicationController::class, 'accept']);
    //Route::post('applications/{pitch}/reject', [GrantApplicationController::class, 'reject']);


    Route::post('grant-milestone', [CheckoutStripeController::class, 'grantDisbursement']);
    Route::post('grant-milestone-release-bulk', [GrantController::class, 'release_fst_milestone']);
    Route::post('match-score/{grant_id}', [MatchController::class, 'score']);

    Route::post('update-profile', [GrantController::class, 'update_profile']);
    Route::post('update-user',[GrantController::class,'update_user']);

    Route::post('delete-user',[GrantController::class,'delete_user']);
    Route::post('delete/role-user',[GrantController::class,'delete_roleUser']);

    Route::get('accept-invitation',[GrantController::class,'update_user']);

    # *** Grant New Routes
    Route::get('/{grant}/wallets', [GrantWalletController::class, 'show']);
    Route::post('wallets/{wallet}/deposit', [GrantWalletController::class, 'deposit']);
    Route::post('wallets/{wallet}/deposit-status', [GrantWalletController::class, 'deposit_status']);

    //Route::apiResource('applications/{application}/milestones', GrantMilestoneController::class)->shallow();

    Route::post('applications/{application}/planning-mode', [GrantApplicationController::class, 'setPlanningMode']);

    Route::post('milestones/{milestone}/budget-items', [GrantSupplierController::class, 'budget_item_store']);
    Route::get('milestones/{milestone}/budget-items', [GrantSupplierController::class, 'budget_item_index']);

    Route::post('applications/{application}/award', [GrantMilestoneController::class, 'award']);

    Route::apiResource('milestones/{milestone}/verifications', MilestoneVerificationController::class)->shallow();

    // Grant owner decision actions MPRV (approve / reject / audit)
    Route::patch('verifications/{verification}/approve', [MilestoneVerificationController::class, 'approve']);
    Route::patch('verifications/{verification}/reject', [MilestoneVerificationController::class, 'reject']);
    Route::patch('verifications/{verification}/request-audit', [MilestoneVerificationController::class, 'requestAudit']);


    Route::get('milestones/{milestone}/disbursement-data', [GrantDisbursementController::class, 'disbursementData']);
    Route::apiResource('milestones/{milestone}/disbursements', GrantDisbursementController::class)->shallow();
    // Payment status actions
    Route::patch('disbursements/{disbursement}/mark-completed', [GrantDisbursementController::class, 'markCompleted']);
    Route::patch('disbursements/{disbursement}/mark-failed', [GrantDisbursementController::class, 'markFailed']);
    Route::patch('disbursements/{disbursement}/reverse', [GrantDisbursementController::class, 'reverse']);

    Route::apiResource('milestones/{milestone}/deal-room', GrantDealRoomController::class)
        ->shallow();

    // dealroom application info
    Route::get('dealroom/applications/{application}', [GrantDealRoomController::class, 'show_application']);



    # Completion submissions for milestone
    Route::post('milestones/{milestone}/completions', [MilestoneVerificationController::class, 'store_completion']);
    Route::get('milestones/{milestone}/completions', [MilestoneVerificationController::class, 'index_completions']);
    Route::patch('completions/{completion}/approve', [MilestoneVerificationController::class, 'approve_completion']);
    Route::patch('completions/{completion}/reject', [MilestoneVerificationController::class, 'reject_completion']);

    # G R A N T   R O U N D S
    //Route::get('rounds/{round}', [GrantRoundController::class, 'show']); //added by owen
    Route::apiResource('/{grant}/rounds', GrantRoundController::class)->shallow();
    Route::get('rounds/{round}', [GrantRoundController::class, 'showRound']);
    Route::patch('rounds/{round}', [GrantRoundController::class, 'update']);

    Route::post('rounds/{round}/publish', [ApplicationRoundProgressController::class, 'publish']);

    Route::get('/rounds/{round}/applications', [GrantRoundController::class, 'applications']);
    Route::get('/rounds/{round}/applications/active', [ApplicationRoundProgressController::class, 'activeApplications']);
    Route::get('/rounds/{round}/applications/advanced', [ApplicationRoundProgressController::class, 'advancedApplications']);
    Route::get('/rounds/{round}/applications/not_selected', [ApplicationRoundProgressController::class, 'notSelectedApplications']);

    Route::get('/rounds/applications/{application}', [GrantRoundController::class, 'application_show']);

    // submit to other rounds
    Route::post('applications/{application}/current-round/submit', [GrantRoundController::class, 'submitRound']);

    Route::get('applications/{application}/rounds-history', [GrantApplicationController::class, 'roundsHistory']);
    Route::get('rounds/{round}/rounds-history', [GrantApplicationController::class, 'roundsHistoryByRound']);
    Route::get('applications/{application}/rounds', [ApplicationRoundProgressController::class, 'index']);


    // Questions & Answers
    Route::apiResource('rounds/{round}/questions', RoundQuestionController::class)->shallow();
    Route::patch('questions/{question}', [RoundQuestionController::class, 'update']); //added by owen

    Route::prefix('applications/{application_id}/rounds/{round_id}')->group(function () {
        Route::post('/answer', [RoundQuestionController::class, 'submitAnswer']);
        Route::get('/answers', [RoundQuestionController::class, 'getAnswers']);
        Route::delete('/answers/{question_id}', [RoundQuestionController::class, 'deleteAnswer']);
    });

    Route::get('reviewer/assigned-rounds', [RoundReviewerController::class, 'rounds']); //added by owen

    Route::apiResource('rounds/{round}/reviewers', RoundReviewerController::class)->shallow();
    Route::patch('reviewers/{reviewer}', [RoundReviewerController::class, 'update']); //added by owen

    // ROUND REQUIRED DOCUMENTS
    Route::post('applications/{application_id}/rounds/{round_id}/documents', [RoundDocumentController::class, 'store']);
    Route::get('applications/{application_id}/rounds/{round_id}/documents', [RoundDocumentController::class, 'index']);
    // Grant owner verifies/rejects documents
    Route::patch('documents/{document_id}/verify', [RoundDocumentController::class, 'verify']);
    Route::patch('documents/{document_id}/reject', [RoundDocumentController::class, 'reject']);
    Route::delete('documents/{document_id}', [RoundDocumentController::class, 'destroy']);


    // Scoring rounds
    Route::post('applications/{application}/scores', [ApplicationScoreController::class, 'store']);
    Route::post('rounds/{round}/finalize', [GrantRoundController::class, 'finalize']); // Process advancement
    Route::post('applications/{application}/advance', [GrantRoundController::class, 'advanceManual']); // Manual advance
    Route::post('applications/{application}/reject', [GrantRoundController::class, 'rejectManual']);



    // Funding Setup & Supplier Directory
    Route::get('supplier-directory/{supplierId}/assigned-milestones', [SupplierDirectoryController::class, 'assignedMilestones']);
    Route::apiResource('supplier-directory', SupplierDirectoryController::class);
    // Add supplier to milestone
    Route::post('milestones/{milestone}/assign-suppliers', [SupplierDirectoryController::class, 'assignToMilestone']);

    //old route
    Route::apiResource('milestones/{milestone}/suppliers', GrantSupplierController::class)->shallow();

    Route::prefix('applications/{application_id}/milestones')->group(function () {

        Route::post('/templates', [GrantMilestoneController::class, 'storeTemplate']);

        Route::get('/templates', [GrantMilestoneController::class, 'listTemplates']);

        // Activate all templates (complete funding setup)
        Route::post('/activate', [GrantMilestoneController::class, 'activateTemplates']);

        Route::post('/request-changes', [GrantMilestoneController::class, 'requestChanges']);
        Route::post('/revision-feedback', [GrantMilestoneController::class, 'getRevisionFeedback']);

        // Applicant submits plan for review (hybrid mode)
        Route::post('/submit-plan', [GrantMilestoneController::class, 'submitPlan']);
    });

    // Update/Delete individual template
    Route::patch('milestones/templates/{milestone_id}', [GrantMilestoneController::class, 'updateTemplate']);
    Route::delete('milestones/templates/{milestone_id}', [GrantMilestoneController::class, 'destroyTemplate']);

    Route::prefix('/{grant}/email-templates')->group(function () {
        Route::get('/', [GrantEmailTemplateController::class, 'index']);
        Route::get('{event}', [GrantEmailTemplateController::class, 'show']);
        Route::patch('{event}', [GrantEmailTemplateController::class, 'upsert']);
        Route::delete('{event}', [GrantEmailTemplateController::class, 'destroy']);
    });

    // Milestone Pre-Agreements
    Route::get('milestones/{milestone}/agreements', [MilestonePreAgreementController::class, 'index']);
    Route::post('milestones/{milestone}/agreements/{type}/comment', [MilestonePreAgreementController::class, 'comment']);
    Route::post('milestones/{milestone}/agreements/{type}/approve', [MilestonePreAgreementController::class, 'approve']);
    Route::post('milestones/{milestone}/agreements/{type}/reject', [MilestonePreAgreementController::class, 'reject']);

    // Extra - auto score applications
    Route::post('rounds/{round}/applications/auto-score', [ApplicationRoundProgressController::class, 'autoScoreApplications']);

    // M&E Routes
    Route::prefix('monitoring')->group(function () {
        // Checkpoints
        Route::get('/applications/{app}/checkpoints',      [MEController::class, 'indexCheckpoints']);
        Route::post('/applications/{app}/checkpoints',     [MEController::class, 'storeCheckpoint']);
        Route::patch('/checkpoints/{checkpoint}',             [MEController::class, 'updateCheckpoint']);
        Route::delete('/checkpoints/{checkpoint}',            [MEController::class, 'deleteCheckpoint']);

        // Submissions
        Route::post('/checkpoints/{checkpoint}/submit',       [MEController::class, 'submit']);
        Route::post('/submissions/{submission}/verify',       [MEController::class, 'verify']);
        Route::post('/submissions/{submission}/request-changes', [MEController::class, 'requestChanges']);

        Route::delete('/checkpoints/{checkpoint}/submissions', [MEController::class, 'deleteSubmissions']);

        
        // Site Visits
        Route::post('/checkpoints/{checkpoint}/site-visit/assign', [MEController::class, 'assignSiteVisit']);
        Route::post('/site-visits/{visit}/submit',            [MEController::class, 'submitSiteVisit']);
        Route::get('/site-visits/{visit}',                    [MEController::class, 'showSiteVisit']);
        Route::get('/reviewer/{reviewer_id}/site-visits',     [MEController::class, 'SiteVisits']);

        
        
        // Analytics
        Route::get('/applications/{app}/analytics/overview',    [MEAnalyticsController::class, 'meOverview']);
        Route::get('/applications/{app}/analytics/impact',         [MEAnalyticsController::class, 'applicationImpact']);

        });

});

