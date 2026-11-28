<?php

use App\Http\Controllers\CheckoutStripeController;
/*
|--------------------------------------------------------------------------
| Program Routes
|--------------------------------------------------------------------------
| All program-related routes
| Already wrapped in: middleware(['auth:sanctum', 'extend-token', 'throttle:api'])
*/

use App\Http\Controllers\Misc\AnalyticsController;
use App\Http\Controllers\Misc\MatchController;
use App\Http\Controllers\Organizations\ExternalReviewerController;
use App\Http\Controllers\Program\Ai\AiReviewController;
use App\Http\Controllers\Program\ApplicationRoundProgressController;
use App\Http\Controllers\Program\MEAnalyticsController;
use App\Http\Controllers\Program\MEController;
use App\Http\Controllers\Program\MilestonePreAgreementController;
use App\Http\Controllers\Program\MilestoneVerificationController;
use App\Http\Controllers\Program\ProgramApplicationController;
use App\Http\Controllers\Program\ProgramApplicationsOverviewController;
use App\Http\Controllers\Program\ProgramImportController;
use App\Http\Controllers\Program\ProgramDashboardStatsController;
use App\Http\Controllers\Program\ProgramController;
use App\Http\Controllers\Program\ProgramDealRoomController;
use App\Http\Controllers\Program\ProgramDisbursementController;
use App\Http\Controllers\Program\ProgramEmailTemplateController;
use App\Http\Controllers\Program\ProgramMilestoneController;
use App\Http\Controllers\Program\ProgramMetadataController;
use App\Http\Controllers\Program\ProgramMediaController;
use App\Http\Controllers\Program\ProgramServiceController;
use App\Http\Controllers\Program\ProgramSupplierController;
use App\Http\Controllers\Program\ProgramWalletController;
use App\Http\Controllers\Program\SiteVisitController;
use App\Http\Controllers\Program\Rounds\ApplicationScoreController;
use App\Http\Controllers\Program\Rounds\ProgramRoundController;
use App\Http\Controllers\Program\Rounds\RoundDocumentController;
use App\Http\Controllers\Program\Rounds\RoundQuestionController;
use App\Http\Controllers\Program\GoogleFormImportController;
use App\Http\Controllers\Program\Rounds\RoundReviewerController;
use App\Http\Controllers\Program\ReviewerOrderController;
use App\Http\Controllers\Program\SupplierDirectoryController;
use App\Http\Controllers\TestData\TestApplicationGeneratorController;
use Illuminate\Support\Facades\Route;

Route::get('programs/{program_id}/sme/applications', [ProgramApplicationController::class, 'application_info']);


Route::prefix('/programs')->middleware(['program', 'program.response'])->group(function () {
    Route::get('ai/usage/me', [\App\Http\Controllers\AiUsageController::class, 'me']);
    Route::get('ai/usage/history', [\App\Http\Controllers\AiUsageController::class, 'history']);
    Route::get('applications/{application}/ai-usage', [\App\Http\Controllers\AiUsageController::class, 'application'])->whereNumber('application');
    // AI-assisted / AI-only review (static paths first)
    Route::post('ai-privacy-ack', [AiReviewController::class, 'privacyAck']);
    Route::get('rounds/{round}/ai-reviews', [AiReviewController::class, 'roundOverview'])->whereNumber('round');
    Route::get('rounds/{round}/ai-reviews/activity', [AiReviewController::class, 'roundActivity'])->whereNumber('round');
    Route::get('rounds/{round}/ai-reviews/items', [AiReviewController::class, 'roundItems'])->whereNumber('round');
    Route::post('rounds/{round}/ai-reviews/resume', [AiReviewController::class, 'resume'])->whereNumber('round');
    Route::post('rounds/{round}/ai-reviews/stop', [AiReviewController::class, 'stop'])->whereNumber('round');
    Route::post('rounds/{round}/ai-reviews/rerun-attention', [AiReviewController::class, 'rerunAttention'])->whereNumber('round');
    Route::get('applications/{application}/ai-review', [AiReviewController::class, 'show'])->whereNumber('application');
    Route::post('applications/{application}/ai-review/rerun', [AiReviewController::class, 'rerun'])->whereNumber('application')->middleware('throttle:'.config('ai_review.rerun_throttle_per_minute', 6).',1');
    Route::post('applications/{application}/ai-review/findings', [AiReviewController::class, 'storeFinding'])->whereNumber('application');
    Route::get('applications/{application}/answers/{answer}/file', [\App\Http\Controllers\Program\ApplicationFileController::class, 'answerFile'])->whereNumber(['application', 'answer']);
    Route::get('applications/{application}/documents/{document}/file', [\App\Http\Controllers\Program\ApplicationFileController::class, 'documentFile'])->whereNumber(['application', 'document']);
    Route::patch('ai-findings/{finding}', [AiReviewController::class, 'updateFinding'])->whereNumber('finding');

    Route::get('program-metadata', [ProgramMetadataController::class, 'index']);
    Route::get('program-metadata/{program_industry_id}', [ProgramMetadataController::class, 'index'])->whereNumber('program_industry_id');
    Route::post('program-metadata', [ProgramMetadataController::class, 'store']);

    // Program
    Route::get('programs', [ProgramController::class, 'index']);
    Route::get('public-programs', [ProgramController::class, 'public_programs']);
    Route::get('get_program/{id}', [ProgramController::class, 'get_program']);

    Route::get('supplier-directory/providers', [SupplierDirectoryController::class, 'providers']);

    Route::get('{program_id}/applications', [ProgramApplicationController::class, 'index']);
    Route::get('{program_id}/applications/awarded', [ProgramApplicationController::class, 'awarded']);
    // Owner Program Deal Room: every awarded application across the owner's programs, as a server list
    Route::get('owner/awarded-applications', [ProgramApplicationController::class, 'ownerAwarded']);
    Route::get('applications/{id}', [ProgramApplicationController::class, 'show']);
    Route::get('my-applications', [ProgramApplicationController::class, 'myPitches']);
    // Owner Applications page: one summary row per program (counts done by the database)
    Route::get('applications-overview', [ProgramApplicationsOverviewController::class, 'index']);
    // Program owner dashboard cards (one consistent snapshot)
    Route::get('dashboard-stats', [ProgramDashboardStatsController::class, 'index']);
    Route::get('visibility/{program_id}', [ProgramController::class, 'visibility']);
    Route::delete('delete-program/{id}', [ProgramController::class, 'destroy']);

    Route::get('fund-release-request/{pitch_id}', [ProgramApplicationController::class, 'fund_request']);
    Route::get('analytics', [AnalyticsController::class, 'index']);
    Route::get('programWritingServices', [ProgramServiceController::class, 'programWritingServices']);
    Route::get('store-watchlist/{pitch_id}', [ProgramController::class, 'store_watchlist']);
    Route::get('get-watchlist', [ProgramController::class, 'get_watchlist']);

    Route::post('create-program', [ProgramController::class, 'store'])->middleware('subscribed');
    Route::post('programs/{program}', [ProgramController::class, 'update']);
    // Cover image and intro video shown to applicants (removing is a POST so Editors may do it)
    Route::post('programs/{program}/cover', [ProgramMediaController::class, 'uploadCover'])->whereNumber('program');
    Route::post('programs/{program}/cover/remove', [ProgramMediaController::class, 'removeCover'])->whereNumber('program');
    Route::post('programs/{program}/intro-video', [ProgramMediaController::class, 'setVideo'])->whereNumber('program');
    Route::post('programs/{program}/intro-video/remove', [ProgramMediaController::class, 'removeVideo'])->whereNumber('program');
    // The app calls POST /programs/{id}/duplicate. The older doubled path stays so nothing that used it breaks.
    Route::post('{program}/duplicate', [ProgramController::class, 'duplicate'])->whereNumber('program');
    Route::post('/programs/{program}/duplicate', [ProgramController::class, 'duplicate']);

    // for SME
    Route::get('/{program_id}/sme/applications', [ProgramApplicationController::class, 'application_info']);
    Route::get('/my-applications/awarded', [ProgramApplicationController::class, 'smeAwarded']);

    Route::post('applications/{pitch}/accept', [ProgramApplicationController::class, 'accept']);
    // Route::post('applications/{pitch}/reject', [ProgramApplicationController::class, 'reject']);

    Route::post('program-milestone', [CheckoutStripeController::class, 'programDisbursement']);
    Route::post('program-milestone-release-bulk', [ProgramController::class, 'release_fst_milestone']);
    Route::post('match-score/{program_id}', [MatchController::class, 'score']);

    Route::post('update-profile', [ProgramController::class, 'update_profile']);
    Route::post('update-user', [ProgramController::class, 'update_user']);

    Route::post('delete-user', [ProgramController::class, 'delete_user']);
    Route::post('delete/role-user', [ProgramController::class, 'delete_roleUser']);

    // (GET accept-invitation used to run update_user, letting anyone change roles with a link: removed. Invitations are
    // accepted at POST organization-invitations/accept.)

    // *** Program New Routes
    // What the owner reads on the program page: applicants, the money ledger and the activity feed (read only, paged)
    Route::get('{program}/overview/applications', [\App\Http\Controllers\Program\ProgramReportController::class, 'applications'])->whereNumber('program');
    Route::get('{program}/overview/wallet', [\App\Http\Controllers\Program\ProgramReportController::class, 'wallet'])->whereNumber('program');
    Route::get('{program}/overview/activity', [\App\Http\Controllers\Program\ProgramReportController::class, 'activity'])->whereNumber('program');
    Route::get('/{program}/wallets', [ProgramWalletController::class, 'show']);
    Route::post('wallets/{wallet}/deposit', [ProgramWalletController::class, 'deposit']);
    Route::post('wallets/{wallet}/deposit-status', [ProgramWalletController::class, 'deposit_status']);

    Route::post('wallets/{wallet}/transfer', [ProgramWalletController::class, 'transferFromMainWallet']);
    Route::post('wallets/{wallet}/move-back', [\App\Http\Controllers\Program\OrganizationWalletController::class, 'moveBack']);
    // The organization wallet (DECISION-6): its own LIPR account and KES balance; Super Admins manage it
    Route::get('organization-wallet', [\App\Http\Controllers\Program\OrganizationWalletController::class, 'show']);
    Route::post('organization-wallet/setup', [\App\Http\Controllers\Program\OrganizationWalletController::class, 'setup']);
    Route::post('organization-wallet/deposit', [\App\Http\Controllers\Program\OrganizationWalletController::class, 'deposit']);
    Route::post('organization-wallet/deposit-status', [\App\Http\Controllers\Program\OrganizationWalletController::class, 'depositStatus']);
    Route::post('organization-wallet/withdraw/code', [\App\Http\Controllers\Program\OrganizationWalletController::class, 'withdrawCode'])->middleware('throttle:6,10');
    Route::post('organization-wallet/withdraw', [\App\Http\Controllers\Program\OrganizationWalletController::class, 'withdraw']);

    // Route::apiResource('applications/{application}/milestones', ProgramMilestoneController::class)->shallow();

    Route::post('applications/{application}/planning-mode', [ProgramApplicationController::class, 'setPlanningMode']);

    Route::post('milestones/{milestone}/budget-items', [ProgramSupplierController::class, 'budget_item_store']);
    Route::get('milestones/{milestone}/budget-items', [ProgramSupplierController::class, 'budget_item_index']);
    Route::delete('milestones/{milestone}/budget-items/{budgetItemId}', [ProgramSupplierController::class, 'budget_item_destroy']);

    Route::post('applications/{application}/award', [ProgramMilestoneController::class, 'award']);

    Route::apiResource('milestones/{milestone}/verifications', MilestoneVerificationController::class)->shallow();

    // Program owner decision actions MPRV (approve / reject / audit)
    Route::patch('verifications/{verification}/approve', [MilestoneVerificationController::class, 'approve']);
    Route::patch('verifications/{verification}/reject', [MilestoneVerificationController::class, 'reject']);
    Route::patch('verifications/{verification}/request-modification', [MilestoneVerificationController::class, 'requestModification']);
    Route::patch('verifications/{verification}/request-audit', [MilestoneVerificationController::class, 'requestAudit']);
    // DECISION-9 / DECISION-8 change: pay the quoted audit fee, skip a suggested audit, cancel an open one
    Route::patch('verifications/{verification}/pay-audit', [\App\Http\Controllers\Audits\ProjectManagerAuditController::class, 'programPay']);
    Route::patch('verifications/{verification}/skip-audit', [\App\Http\Controllers\Audits\ProjectManagerAuditController::class, 'programSkip']);
    Route::patch('verifications/{verification}/cancel-audit', [\App\Http\Controllers\Audits\ProjectManagerAuditController::class, 'programCancel']);

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

    // Completion submissions for milestone
    Route::post('milestones/{milestone}/completions', [MilestoneVerificationController::class, 'store_completion']);
    Route::get('milestones/{milestone}/completions', [MilestoneVerificationController::class, 'index_completions']);
    Route::patch('completions/{completion}/approve', [MilestoneVerificationController::class, 'approve_completion']);
    Route::patch('completions/{completion}/reject', [MilestoneVerificationController::class, 'reject_completion']);

    // G R A N T   R O U N D S
    // Route::get('rounds/{round}', [ProgramRoundController::class, 'show']); //added by owen
    // `show` is showRound below (the resource's own GET rounds/{round} had no method and answered 500)
    Route::apiResource('/{program}/rounds', ProgramRoundController::class)->shallow()->except(['show']);
    Route::get('rounds/{round}', [ProgramRoundController::class, 'showRound']);
    Route::patch('rounds/{round}', [ProgramRoundController::class, 'update']);
    Route::post('rounds/{round}/configuration-steps', [ProgramRoundController::class, 'confirmStep']);
    Route::delete('rounds/{round}', [ProgramRoundController::class, 'destroy']);

    Route::post('rounds/{round}/publish', [ApplicationRoundProgressController::class, 'publish'])->middleware('subscribed');

    Route::get('/rounds/{round}/applications', [ProgramRoundController::class, 'applications']);
    // Decide by score: what a score line would do (preview), and deciding many applicants at once (checked on the server)
    Route::get('/rounds/{round}/applications/score-line', [ProgramRoundController::class, 'scoreLine'])->whereNumber('round');
    Route::post('/rounds/{round}/applications/decide', [ProgramRoundController::class, 'decideMany'])->whereNumber('round');
    // Everyone in the program once, in the round they are in now (the all-rounds applications table; shared list request)
    Route::get('{program}/round-applications', [ProgramRoundController::class, 'programApplications'])->whereNumber('program');
    Route::get('/rounds/{round}/finalize-preview', [ProgramRoundController::class, 'finalizePreview']);
    Route::post('/rounds/{round}/reviewer-payments', [ProgramRoundController::class, 'startPayments']);
    Route::get('/rounds/{round}/applications/active', [ApplicationRoundProgressController::class, 'activeApplications']);
    Route::get('/rounds/{round}/applications/advanced', [ApplicationRoundProgressController::class, 'advancedApplications']);
    Route::get('/rounds/{round}/applications/not_selected', [ApplicationRoundProgressController::class, 'notSelectedApplications']);

    Route::get('/rounds/applications/{application}', [ProgramRoundController::class, 'application_show']);
    // One application as it stands in one round (answers and scores only once the applicant has submitted it)
    Route::get('/rounds/{round}/applications/{application}', [ProgramRoundController::class, 'roundApplicationShow'])->whereNumber(['round', 'application']);

    // submit to other rounds
    Route::post('applications/{application}/current-round/submit', [ProgramRoundController::class, 'submitRound']);

    Route::get('applications/{application}/rounds-history', [ProgramApplicationController::class, 'roundsHistory']);
    Route::get('rounds/{round}/rounds-history', [ProgramApplicationController::class, 'roundsHistoryByRound']);
    Route::get('applications/{application}/rounds', [ApplicationRoundProgressController::class, 'index']);

    // Program imports (Programs → Imports): the import's choices, the step reached and its file, so it can be continued
    Route::get('imports', [ProgramImportController::class, 'index']);
    Route::post('imports', [ProgramImportController::class, 'store'])->middleware('subscribed');
    Route::get('imports/program-state/{program}', [ProgramImportController::class, 'programState'])->whereNumber('program');
    Route::get('imports/{import}', [ProgramImportController::class, 'show'])->whereNumber('import');
    Route::patch('imports/{import}', [ProgramImportController::class, 'update'])->whereNumber('import');
    Route::post('imports/{import}/file', [ProgramImportController::class, 'uploadFile'])->whereNumber('import');
    Route::get('imports/{import}/file', [ProgramImportController::class, 'downloadFile'])->whereNumber('import');
    Route::post('imports/{import}/clear-file', [ProgramImportController::class, 'removeFile'])->whereNumber('import');
    Route::get('imports/{import}/check', [ProgramImportController::class, 'check'])->whereNumber('import');
    Route::post('imports/{import}/start', [ProgramImportController::class, 'start'])->whereNumber('import')->middleware('subscribed');
    Route::post('imports/{import}/people', [ProgramImportController::class, 'people'])->whereNumber('import')->middleware('throttle:30,1');
    Route::post('imports/{import}/suppliers', [ProgramImportController::class, 'suppliers'])->whereNumber('import')->middleware('throttle:30,1');
    Route::post('imports/{import}/invite-coaches', [ProgramImportController::class, 'inviteCoaches'])->whereNumber('import')->middleware('throttle:5,1');
    Route::post('imports/{import}/notify-inspectors', [ProgramImportController::class, 'notifyInspectors'])->whereNumber('import')->middleware('throttle:5,1');
    Route::post('imports/{import}/notify-applicants', [ProgramImportController::class, 'notifyApplicants'])->whereNumber('import')->middleware('throttle:5,1');
    Route::post('imports/{import}/notify-suppliers', [ProgramImportController::class, 'notifySuppliers'])->whereNumber('import')->middleware('throttle:5,1');
    Route::post('imports/{import}/ai-review', [ProgramImportController::class, 'aiReview'])->whereNumber('import')->middleware('throttle:5,1');
    Route::post('imports/{import}/ai-fix', [ProgramImportController::class, 'aiFix'])->whereNumber('import')->middleware('throttle:10,1');
    Route::delete('imports/{import}', [ProgramImportController::class, 'destroy'])->whereNumber('import');

    // Questions & Answers
    Route::post('questions/import/google-form', [GoogleFormImportController::class, 'preview'])->middleware('throttle:20,1');
    Route::apiResource('rounds/{round}/questions', RoundQuestionController::class)->shallow();
    Route::patch('questions/{question}', [RoundQuestionController::class, 'update']); // added by owen
    Route::delete('questions/{question}', [RoundQuestionController::class, 'destroy']);
    Route::delete('rounds/{round}/questions', [RoundQuestionController::class, 'destroyMany']);

    Route::prefix('applications/{application_id}/rounds/{round_id}')->group(function () {
        Route::post('/answer', [RoundQuestionController::class, 'submitAnswer']);
        Route::get('/answers', [RoundQuestionController::class, 'getAnswers']);
        Route::delete('/answers/{question_id}', [RoundQuestionController::class, 'deleteAnswer']);
    });

    //Route::get('reviewer/assigned-rounds', [RoundReviewerController::class, 'rounds']); // added by owen
    Route::get('reviewer/assigned-rounds', [RoundReviewerController::class, 'rounds']); // added by owen

    Route::apiResource('rounds/{round}/reviewers', RoundReviewerController::class)->shallow();
    Route::patch('reviewers/{reviewer}', [RoundReviewerController::class, 'update']); // added by owen

    // ─── REVIEWER Assignments & ORDERS ──────────────────────────────────────────────────
    Route::post('rounds/{round}/reviewers/accept',   [RoundReviewerController::class, 'accept']);
    Route::post('rounds/{round}/reviewers/decline',  [RoundReviewerController::class, 'decline']);
    Route::post('rounds/{round}/reviewers/{reviewer}/assign-applications', [RoundReviewerController::class, 'assignApplications']);
    Route::delete('rounds/{round}/applications/{application}/assignment', [RoundReviewerController::class, 'unassignApplication']);
    Route::post('rounds/{round}/applications/{application}/start-review',  [RoundReviewerController::class, 'startReview']);
    Route::get('rounds/{round}/my-applications',     [RoundReviewerController::class, 'myAssignedApplications']);


    Route::get('reviewer/assigned-requests', [RoundReviewerController::class, 'myAssignedRequests']);
    Route::get('reviewer/assignment-counts', [RoundReviewerController::class, 'assignmentCounts']);

    // Service providers: the work organizations assigned to them, and the list organizations search
    Route::get('provider/work', [\App\Http\Controllers\Program\ProviderWorkController::class, 'index']);
    Route::get('third-party-providers', [\App\Http\Controllers\Program\ProviderWorkController::class, 'providers']);

    Route::get('rounds/{round}/reviewer-orders', [ReviewerOrderController::class, 'roundOrders']);
    Route::get('owner/reviewer-orders', [ReviewerOrderController::class, 'ownerOrders']);
    Route::post('reviewer-orders/{order}/deliver', [ReviewerOrderController::class, 'deliver']);
    Route::post('reviewer-orders/{order}/request-modification', [ReviewerOrderController::class, 'requestModification']);
    Route::post('reviewer-orders/{order}/approve', [ReviewerOrderController::class, 'approve']);
    Route::post('reviewer-orders/{order}/reject', [ReviewerOrderController::class, 'reject']);
    Route::get('reviewer-orders/{order}/payment-status', [ReviewerOrderController::class, 'paymentStatus']);

    Route::get('reviewer/orders', [ReviewerOrderController::class, 'myOrders']);

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
    // Finish a funded application's monitoring without (more) checkpoints: it is then completed (owner decision 27 Nov)
    Route::post('applications/{application}/close-monitoring', [\App\Http\Controllers\Program\MonitoringCloseController::class, 'close']);

    // Funding Setup & Supplier Directory
    Route::get('supplier-directory/{supplierId}/assigned-milestones', [SupplierDirectoryController::class, 'assignedMilestones']);
    Route::post('supplier-directory/{supplierId}/activate', [SupplierDirectoryController::class, 'activate']);
    Route::post('supplier-directory/{supplierId}/deactivate', [SupplierDirectoryController::class, 'deactivate']);
    Route::apiResource('supplier-directory', SupplierDirectoryController::class);
    // Add supplier to milestone
    Route::post('milestones/{milestone}/assign-suppliers', [SupplierDirectoryController::class, 'assignToMilestone']);

    // old route
    Route::apiResource('milestones/{milestone}/suppliers', ProgramSupplierController::class)->shallow();

    Route::prefix('applications/{application_id}/milestones')->group(function () {

        Route::post('/templates', [ProgramMilestoneController::class, 'storeTemplate']);

        Route::get('/templates', [ProgramMilestoneController::class, 'listTemplates']);

        // Activate all templates (complete funding setup)
        Route::post('/activate', [ProgramMilestoneController::class, 'activateTemplates']);

        Route::post('/request-changes', [ProgramMilestoneController::class, 'requestChanges']);
        Route::get('/revision-feedback', [ProgramMilestoneController::class, 'getRevisionFeedback']);

        // Applicant / PO submits plan for review (hybrid mode)
        Route::post('/submit-plan', [ProgramMilestoneController::class, 'submitPlan']);
    });

    // Update/Delete individual template
    Route::patch('milestones/templates/{milestone_id}', [ProgramMilestoneController::class, 'updateTemplate']);
    Route::delete('milestones/templates/{milestone_id}', [ProgramMilestoneController::class, 'destroyTemplate']);

    Route::prefix('/{program}/email-templates')->group(function () {
        Route::get('/', [ProgramEmailTemplateController::class, 'index']);
        // Before {event}: these two are not events
        Route::get('defaults', [ProgramEmailTemplateController::class, 'defaults']);
        Route::patch('language', [ProgramEmailTemplateController::class, 'language']);
        Route::get('{event}', [ProgramEmailTemplateController::class, 'show']);
        Route::patch('{event}', [ProgramEmailTemplateController::class, 'upsert']);
        Route::delete('{event}', [ProgramEmailTemplateController::class, 'destroy']);
    });

    // Milestone Pre-Agreements
    Route::get('milestones/{milestone}/agreements', [MilestonePreAgreementController::class, 'index']);
    Route::post('milestones/{milestone}/agreements/{type}/read', [MilestonePreAgreementController::class, 'markRead']);
    Route::post('milestones/{milestone}/agreements/{type}/comment', [MilestonePreAgreementController::class, 'comment']);
    Route::post('milestones/{milestone}/agreements/{type}/approve', [MilestonePreAgreementController::class, 'approve']);
    Route::post('milestones/{milestone}/agreements/{type}/reject', [MilestonePreAgreementController::class, 'reject']);
    Route::post('milestones/{milestone}/agreements/{type}/reopen', [MilestonePreAgreementController::class, 'reopen']);

    // programs disbursement
    Route::post('milestones/{milestone}/release-funds', [ProgramDisbursementController::class, 'releaseFunds']);

    // Extra - auto score applications
    Route::post('rounds/{round}/applications/auto-score', [ApplicationRoundProgressController::class, 'autoScoreApplications']);

    // M&E Routes
    Route::prefix('monitoring')->group(function () {
        // Checkpoints
        Route::get('/applications/{app}/checkpoints', [MEController::class, 'indexCheckpoints']);
        Route::post('/applications/{app}/checkpoints', [MEController::class, 'storeCheckpoint']);
        // What was assigned to the signed-in team member (their requests)
        Route::get('/assigned-checkpoints', [MEController::class, 'assignedCheckpoints']);
        Route::post('/checkpoints/{checkpoint}/send', [MEController::class, 'sendCheckpoint']);
        Route::patch('/checkpoints/{checkpoint}', [MEController::class, 'updateCheckpoint']);
        Route::delete('/checkpoints/{checkpoint}', [MEController::class, 'deleteCheckpoint']);

        // Submissions
        Route::post('/checkpoints/{checkpoint}/submit', [MEController::class, 'submit']);
        Route::post('/submissions/{submission}/verify', [MEController::class, 'verify']);
        Route::post('/submissions/{submission}/request-changes', [MEController::class, 'requestChanges']);
        Route::delete('/submission-files/{file}', [MEController::class, 'deleteSubmissionFile']);

        Route::delete('/checkpoints/{checkpoint}/submissions', [MEController::class, 'deleteSubmissions']);

        // Site Visits
        Route::post('/checkpoints/{checkpoint}/site-visit/assign', [SiteVisitController::class, 'assignSiteVisit']);
        Route::get('/programs/{program}/site-visit-orders', [SiteVisitController::class, 'ownerOrders']);
        Route::post('/site-visits/{visit}/accept', [SiteVisitController::class, 'acceptSiteVisit']);
        Route::post('/site-visits/{visit}/propose-fee', [SiteVisitController::class, 'proposeFee']);
        Route::post('/reviewer-orders/{order}/site-visit/answer-fee', [SiteVisitController::class, 'answerFee']);
        Route::post('/site-visits/{visit}/decline', [SiteVisitController::class, 'declineSiteVisit']);
        Route::post('/site-visits/{visit}/submit', [SiteVisitController::class, 'submitSiteVisit']);
        Route::get('/site-visits/{visit}', [SiteVisitController::class, 'showSiteVisit']);
        Route::get('/reviewer/{reviewer_id}/site-visits', [SiteVisitController::class, 'SiteVisits']);
        Route::post('/reviewer-orders/{order}/site-visit/request-changes', [SiteVisitController::class, 'requestChanges']);
        Route::post('/reviewer-orders/{order}/site-visit/approve', [SiteVisitController::class, 'approveOrder']);
        Route::post('/reviewer-orders/{order}/site-visit/reject', [SiteVisitController::class, 'rejectOrder']);
        Route::post('/reviewer-orders/{order}/site-visit/retry-payment', [SiteVisitController::class, 'retryPayment']);

        // Analytics
        Route::get('/applications/{app}/analytics/overview', [MEAnalyticsController::class, 'meOverview']);
        Route::get('/applications/{app}/analytics/impact', [MEAnalyticsController::class, 'applicationImpact']);

    });

    // ─── TEST DATA GENERATION (Non-Production Only) ─────────────────────────────────
    Route::prefix('test-data')->group(function () {
        Route::post('/generate-applications', [TestApplicationGeneratorController::class, 'generateApplications']);
    });

});
