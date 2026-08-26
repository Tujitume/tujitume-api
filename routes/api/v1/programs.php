<?php

use Illuminate\Support\Facades\Route;
/*
|--------------------------------------------------------------------------
| Program Routes
|--------------------------------------------------------------------------
| All program-related routes
| Already wrapped in: middleware(['auth:sanctum', 'extend-token', 'throttle:api'])
*/

use App\Http\Controllers\CheckoutStripeController;
use App\Http\Controllers\Program\ApplicationRoundProgressController;
use App\Http\Controllers\Program\ProgramApplicationController;
use App\Http\Controllers\Program\ProgramController;
use App\Http\Controllers\Program\ProgramDealRoomController;
use App\Http\Controllers\Program\ProgramDisbursementController;
use App\Http\Controllers\Program\ProgramEmailTemplateController;
use App\Http\Controllers\Program\ProgramMilestoneController;
use App\Http\Controllers\Program\ProgramServiceController;
use App\Http\Controllers\Program\ProgramSupplierController;
use App\Http\Controllers\Program\ProgramWalletController;
use App\Http\Controllers\Program\MEAnalyticsController;
use App\Http\Controllers\Program\MEController;
use App\Http\Controllers\Program\MilestonePreAgreementController;
use App\Http\Controllers\Program\MilestoneVerificationController;
use App\Http\Controllers\Program\Rounds\ApplicationScoreController;
use App\Http\Controllers\Program\Rounds\ProgramRoundController;
use App\Http\Controllers\Program\Rounds\RoundDocumentController;
use App\Http\Controllers\Program\Rounds\RoundQuestionController;
use App\Http\Controllers\Program\Rounds\RoundReviewerController;
use App\Http\Controllers\Program\SupplierDirectoryController;
use App\Http\Controllers\Misc\AnalyticsController;
use App\Http\Controllers\Misc\MatchController;

Route::prefix('/programs')->middleware(['program'])->group(function(){
    //Program
    Route::get('programs', [ProgramController::class, 'index']);
    Route::get('public-programs', [ProgramController::class, 'public_programs']);
    Route::get('get_program/{id}', [ProgramController::class, 'get_program']);

    Route::get('{program_id}/applications', [ProgramApplicationController::class, 'index']);
    Route::get('{program_id}/applications/awarded', [ProgramApplicationController::class, 'awarded']);
    Route::get('applications/{id}', [ProgramApplicationController::class, 'show']);
    Route::get('my-applications', [ProgramApplicationController::class, 'myPitches']);
    Route::get('visibility/{program_id}', [ProgramController::class, 'visibility']);
    Route::delete('delete-program/{id}', [ProgramController::class, 'destroy']);

    Route::get('fund-release-request/{pitch_id}', [ProgramApplicationController::class, 'fund_request']);
    Route::get('analytics', [AnalyticsController::class, 'index']);
    Route::get('programWritingServices', [ProgramServiceController::class, 'programWritingServices']);
    Route::get('store-watchlist/{pitch_id}', [ProgramController::class, 'store_watchlist']);
    Route::get('get-watchlist', [ProgramController::class, 'get_watchlist']);

    Route::post('create-program', [ProgramController::class, 'store']);
    Route::post('programs/{program}', [ProgramController::class, 'update']);
    Route::post('/programs/{program}/duplicate', [ProgramController::class, 'duplicate']);

    // for SME
    Route::get('/{program_id}/sme/applications', [ProgramApplicationController::class, 'application_info']);
    Route::get('/my-applications/awarded', [ProgramApplicationController::class, 'smeAwarded']);


    Route::post('/{program}/applications',[ProgramApplicationController::class,'store_application']);
    Route::post('applications/{pitch}/accept', [ProgramApplicationController::class, 'accept']);
    //Route::post('applications/{pitch}/reject', [ProgramApplicationController::class, 'reject']);


    Route::post('program-milestone', [CheckoutStripeController::class, 'programDisbursement']);
    Route::post('program-milestone-release-bulk', [ProgramController::class, 'release_fst_milestone']);
    Route::post('match-score/{program_id}', [MatchController::class, 'score']);

    Route::post('update-profile', [ProgramController::class, 'update_profile']);
    Route::post('update-user',[ProgramController::class,'update_user']);

    Route::post('delete-user',[ProgramController::class,'delete_user']);
    Route::post('delete/role-user',[ProgramController::class,'delete_roleUser']);

    Route::get('accept-invitation',[ProgramController::class,'update_user']);

    # *** Program New Routes
    Route::get('/{program}/wallets', [ProgramWalletController::class, 'show']);
    Route::post('wallets/{wallet}/deposit', [ProgramWalletController::class, 'deposit']);
    Route::post('wallets/{wallet}/deposit-status', [ProgramWalletController::class, 'deposit_status']);

    //Route::apiResource('applications/{application}/milestones', ProgramMilestoneController::class)->shallow();

    Route::post('applications/{application}/planning-mode', [ProgramApplicationController::class, 'setPlanningMode']);

    Route::post('milestones/{milestone}/budget-items', [ProgramSupplierController::class, 'budget_item_store']);
    Route::get('milestones/{milestone}/budget-items', [ProgramSupplierController::class, 'budget_item_index']);

    Route::post('applications/{application}/award', [ProgramMilestoneController::class, 'award']);

    Route::apiResource('milestones/{milestone}/verifications', MilestoneVerificationController::class)->shallow();

    // Program owner decision actions MPRV (approve / reject / audit)
    Route::patch('verifications/{verification}/approve', [MilestoneVerificationController::class, 'approve']);
    Route::patch('verifications/{verification}/reject', [MilestoneVerificationController::class, 'reject']);
    Route::patch('verifications/{verification}/request-audit', [MilestoneVerificationController::class, 'requestAudit']);


    Route::get('milestones/{milestone}/disbursement-data', [ProgramDisbursementController::class, 'disbursementData']);
    Route::apiResource('milestones/{milestone}/disbursements', ProgramDisbursementController::class)->shallow();
    // Payment status actions
    Route::patch('disbursements/{disbursement}/mark-completed', [ProgramDisbursementController::class, 'markCompleted']);
    Route::patch('disbursements/{disbursement}/mark-failed', [ProgramDisbursementController::class, 'markFailed']);
    Route::patch('disbursements/{disbursement}/reverse', [ProgramDisbursementController::class, 'reverse']);

    Route::apiResource('milestones/{milestone}/deal-room', ProgramDealRoomController::class)
        ->shallow();

    // dealroom application info
    Route::get('dealroom/applications/{application}', [ProgramDealRoomController::class, 'show_application']);



    # Completion submissions for milestone
    Route::post('milestones/{milestone}/completions', [MilestoneVerificationController::class, 'store_completion']);
    Route::get('milestones/{milestone}/completions', [MilestoneVerificationController::class, 'index_completions']);
    Route::patch('completions/{completion}/approve', [MilestoneVerificationController::class, 'approve_completion']);
    Route::patch('completions/{completion}/reject', [MilestoneVerificationController::class, 'reject_completion']);

    # G R A N T   R O U N D S
    //Route::get('rounds/{round}', [ProgramRoundController::class, 'show']); //added by owen
    Route::apiResource('/{program}/rounds', ProgramRoundController::class)->shallow();
    Route::get('rounds/{round}', [ProgramRoundController::class, 'showRound']);
    Route::patch('rounds/{round}', [ProgramRoundController::class, 'update']);

    Route::post('rounds/{round}/publish', [ApplicationRoundProgressController::class, 'publish']);

    Route::get('/rounds/{round}/applications', [ProgramRoundController::class, 'applications']);
    Route::get('/rounds/{round}/applications/active', [ApplicationRoundProgressController::class, 'activeApplications']);
    Route::get('/rounds/{round}/applications/advanced', [ApplicationRoundProgressController::class, 'advancedApplications']);
    Route::get('/rounds/{round}/applications/not_selected', [ApplicationRoundProgressController::class, 'notSelectedApplications']);

    Route::get('/rounds/applications/{application}', [ProgramRoundController::class, 'application_show']);

    // submit to other rounds
    Route::post('applications/{application}/current-round/submit', [ProgramRoundController::class, 'submitRound']);

    Route::get('applications/{application}/rounds-history', [ProgramApplicationController::class, 'roundsHistory']);
    Route::get('rounds/{round}/rounds-history', [ProgramApplicationController::class, 'roundsHistoryByRound']);
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
    // Program owner verifies/rejects documents
    Route::patch('documents/{document_id}/verify', [RoundDocumentController::class, 'verify']);
    Route::patch('documents/{document_id}/reject', [RoundDocumentController::class, 'reject']);
    Route::delete('documents/{document_id}', [RoundDocumentController::class, 'destroy']);


    // Scoring rounds
    Route::post('applications/{application}/scores', [ApplicationScoreController::class, 'store']);
    Route::post('rounds/{round}/finalize', [ProgramRoundController::class, 'finalize']); // Process advancement
    Route::post('applications/{application}/advance', [ProgramRoundController::class, 'advanceManual']); // Manual advance
    Route::post('applications/{application}/reject', [ProgramRoundController::class, 'rejectManual']);



    // Funding Setup & Supplier Directory
    Route::get('supplier-directory/{supplierId}/assigned-milestones', [SupplierDirectoryController::class, 'assignedMilestones']);
    Route::apiResource('supplier-directory', SupplierDirectoryController::class);
    // Add supplier to milestone
    Route::post('milestones/{milestone}/assign-suppliers', [SupplierDirectoryController::class, 'assignToMilestone']);

    //old route
    Route::apiResource('milestones/{milestone}/suppliers', ProgramSupplierController::class)->shallow();

    Route::prefix('applications/{application_id}/milestones')->group(function () {

        Route::post('/templates', [ProgramMilestoneController::class, 'storeTemplate']);

        Route::get('/templates', [ProgramMilestoneController::class, 'listTemplates']);

        // Activate all templates (complete funding setup)
        Route::post('/activate', [ProgramMilestoneController::class, 'activateTemplates']);

        Route::post('/request-changes', [ProgramMilestoneController::class, 'requestChanges']);
        Route::post('/revision-feedback', [ProgramMilestoneController::class, 'getRevisionFeedback']);

        // Applicant submits plan for review (hybrid mode)
        Route::post('/submit-plan', [ProgramMilestoneController::class, 'submitPlan']);
    });

    // Update/Delete individual template
    Route::patch('milestones/templates/{milestone_id}', [ProgramMilestoneController::class, 'updateTemplate']);
    Route::delete('milestones/templates/{milestone_id}', [ProgramMilestoneController::class, 'destroyTemplate']);

    Route::prefix('/{program}/email-templates')->group(function () {
        Route::get('/', [ProgramEmailTemplateController::class, 'index']);
        Route::get('{event}', [ProgramEmailTemplateController::class, 'show']);
        Route::patch('{event}', [ProgramEmailTemplateController::class, 'upsert']);
        Route::delete('{event}', [ProgramEmailTemplateController::class, 'destroy']);
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

