<?php

namespace App\Http\Controllers\TestData;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Models\Programs\Rounds\RoundRequiredDocument;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\ApplicationRoundHistory;
use App\Service\Program\KnockoutEvaluator;
use App\Service\Program\RoundHistoryService;
use App\Service\Misc\ErrorLogService;
use App\Service\FileUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TestApplicationGeneratorController extends Controller
{
    protected $roundHistory;
    protected $fileUpload;

    public function __construct()
    {
        $this->roundHistory = new RoundHistoryService();
        $this->fileUpload = new FileUpload();
        parent::__construct();
    }

    /**
     * Generate test applications for a specific round
     * POST /api/v1/test-data/generate-applications
     * 
     * Body:
     * {
     *   "round_id": 1,
     *   "num_applications": 10
     * }
     */
    public function generateApplications(Request $request)
    {
        // Only allow in non-production environments
        if (config('app.env') === 'production') {
            return response()->json(['error' => 'Test data generation is not allowed in production'], 403);
        }

        set_time_limit(300); // 5 minutes for large batches

        try {
            $validated = $request->validate([
                'round_id' => 'required|integer|exists:program_rounds,id',
                'num_applications' => 'required|integer|min:1|max:100',
            ]);

            $round = ProgramRound::with([
                'program',
                'questions',
                'knockoutQuestions'
            ])->findOrFail($validated['round_id']);

            $program = $round->program;
            
            // Check if this is the first round
            $isFirstRound = (int)$round->round_number === 1;

            if (!$isFirstRound) {
                return response()->json([
                    'error' => 'Test data can only be generated for Round 1. For subsequent rounds, applications are advanced from previous rounds.'
                ], 422);
            }

            $numToGenerate = $validated['num_applications'];
            $generatedApplications = [];
            $errors = [];

            // Generate applications one by one, with progress tracking
            for ($i = 0; $i < $numToGenerate; $i++) {
                DB::beginTransaction();
                try {
                    $application = $this->createTestApplication($program, $round, $i);
                    $generatedApplications[] = $application;
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    $errors[] = "Application " . ($i + 1) . ": " . $e->getMessage();
                    ErrorLogService::report($e, ['index' => $i]);
                }
            }

            return response()->json([
                'message' => "Successfully generated " . count($generatedApplications) . " test applications for {$round->round_name}",
                'data' => [
                    'round_id' => $round->id,
                    'round_name' => $round->round_name,
                    'program_id' => $program->id,
                    'program_title' => $program->program_title,
                    'applications_generated' => count($generatedApplications),
                    'applications' => collect($generatedApplications)->map(function($app) {
                        return [
                            'id' => $app->id,
                            'startup_name' => $app->startup_name,
                            'status' => $app->status,
                            'round_status' => $app->round_status,
                            'knockout_status' => $app->knockout_status,
                            'match_score' => $app->match_score,
                        ];
                    }),
                    'errors' => $errors,
                ]
            ], 201);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->all()]);
            return response()->json([
                'error' => 'Failed to generate test applications',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a single test application with all required data
     */
    private function createTestApplication(Program $program, ProgramRound $round, int $index): ProgramApplication
    {
        // Create or get test user (SME)
        $testUser = $this->getOrCreateTestUser($index);
        
        // Create or get test business
        $testBusiness = $this->getOrCreateTestBusiness($testUser, $index);

        // Generate application data
        $matchScore = rand(50, 100);
        $applicationData = [
            'program_id' => $program->id,
            'program_owner_id' => $program->user_id,
            'user_id' => $testUser->id,
            'business_id' => $testBusiness->id,
            'current_round_id' => $round->id,
            
            // Business info
            'startup_name' => $testBusiness->name,
            'contact_person_name' => $testUser->first_name . ' ' . $testUser->last_name,
            'contact_person_email' => $testUser->email,
            'sector' => $this->getRandomSector(),
            'headquarters_location' => $this->getRandomLocation(),
            'stage' => $this->getRandomStage(),
            
            // Financials
            'total_amount_requested' => min($program->funding_per_business, rand(50000, $program->funding_per_business)),
            'revenue_last_12_months' => rand(100000, 5000000),
            'team_experience_avg_years' => rand(2, 15),
            
            // Description
            'traction_kpis' => $this->generateLoremIpsum(150),
            
            // Match score
            'match_score' => $matchScore,
            'score_breakdown' => $this->generateScoreBreakdown($matchScore),
            
            // Status - set as submitted for Round 1
            'status' => 'pending',
            'round_status' => 'submitted',
            'knockout_status' => 'pending',
            'submitted_at' => now(),
        ];

        // Create application
        $application = ProgramApplication::create($applicationData);

        // Handle round data (questions, documents, knockout)
        $this->handleRoundQuestions($application, $round);
        $this->handleRoundDocuments($application, $round, $program);
        
        // Evaluate knockout questions
        $knockoutEvaluator = new KnockoutEvaluator();
        $knockoutEvaluator->evaluate($application);
        
        // Create round history
        $this->roundHistory->create($round->id, $application->id, 1, 'in_progress');
        ApplicationRoundHistory::where('application_id', $application->id)
            ->where('round_id', $round->id)
            ->update(['submitted_at' => now()]);

        return $application->fresh();
    }

    /**
     * Get or create test user (SME)
     */
    private function getOrCreateTestUser(int $index): User
    {
        $email = "testuser{$index}@example" . rand(100, 999) . ".com";
        
        // Check if user exists
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            $user = User::create([
                'first_name' => 'Test',
                'last_name' => 'User' . $index,
                'email' => $email,
                'password' => bcrypt('password'),
                'user_type_id' => 1, // SME
                'phone' => '+254700' . str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT),
                'email_verified_at' => now(),
            ]);
        }
        
        return $user;
    }

    /**
     * Get or create test business listing
     */
    private function getOrCreateTestBusiness(User $user, int $index): Listing
    {
        $businessName = $this->generateBusinessName($index);
        
        $business = Listing::where('user_id', $user->id)
            ->where('name', $businessName)
            ->first();
        
        if (!$business) {
            $business = Listing::create([
                'user_id' => $user->id,
                'name' => $businessName,
                'business_type' => $this->getRandomBusinessType(),
                'industry' => $this->getRandomSector(),
                'description' => $this->generateLoremIpsum(200),
                'location' => $this->getRandomLocation(),
                'status' => 'active',
                'founded_year' => rand(2015, 2023),
                'employees_count' => rand(5, 50),
                'annual_revenue' => rand(500000, 5000000),
                'phone' => '+254700' . str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT),
                'email' => $user->email,
                'website' => 'https://www.' . Str::slug($businessName) . '.com',
            ]);
        }
        
        return $business;
    }

    /**
     * Handle round questions (including knockout)
     */
    private function handleRoundQuestions(ProgramApplication $application, ProgramRound $round)
    {
        // Get all questions for this round
        $allQuestions = RoundCustomQuestion::where('round_id', $round->id)->get();
        
        foreach ($allQuestions as $question) {
            $response = $question->question_type === 'knockout' 
                ? $this->generateKnockoutAnswer($question)
                : $this->generateQuestionAnswer($question);
            
            ApplicationRoundResponse::create([
                'application_id' => $application->id,
                'round_id' => $round->id,
                'question_id' => $question->id,
                'response' => $response,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Generate intelligent knockout answer
     * 80% pass, 20% fail for realistic distribution
     */
    private function generateKnockoutAnswer(RoundCustomQuestion $question): string
    {
        $shouldPass = rand(1, 100) <= 80; // 80% pass rate
        
        if (!$shouldPass && $question->knockout_fail_value) {
            // Return the fail value
            return $question->knockout_fail_value;
        }
        
        // Generate a passing answer based on question type
        if ($question->knockout_fail_value) {
            $failValue = strtolower(trim($question->knockout_fail_value));
            
            // If fail value is "no", return "yes"
            if (in_array($failValue, ['no', 'false', '0'])) {
                return 'Yes';
            }
            
            // If fail value is "yes", return "no"
            if (in_array($failValue, ['yes', 'true', '1'])) {
                return 'No';
            }
            
            // For other values, return something different
            return $this->generateLoremIpsum(50);
        }
        
        // Default passing answer
        return 'Yes';
    }

    /**
     * Generate answer for regular questions
     */
    private function generateQuestionAnswer(RoundCustomQuestion $question): string
    {
        if ($question->question_type === 'short_answer') {
            return $this->generateLoremIpsum(rand(30, 100));
        }
        
        // long_text
        return $this->generateLoremIpsum(rand(200, 500));
    }

    /**
     * Handle required documents
     */
    private function handleRoundDocuments(ProgramApplication $application, ProgramRound $round, Program $program)
    {
        $requiredDocs = $round->required_documents ?? [];
        
        if (empty($requiredDocs)) {
            return;
        }
        
        foreach ($requiredDocs as $docConfig) {
            $docType = $docConfig['type'] ?? $docConfig['label'] ?? 'document';
            
            // Create a dummy PDF file
            $filePath = $this->createDummyDocument($program->id, $round->id, $docType);
            
            RoundRequiredDocument::create([
                'application_id' => $application->id,
                'round_id' => $round->id,
                'document_type' => $docType,
                'file_path' => $filePath,
                'verification_status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Create a dummy document file
     */
    private function createDummyDocument(int $programId, int $roundId, string $docType): string
    {
        $dir = "files/programApps/{$programId}/rounds/{$roundId}";
        
        // Ensure directory exists
        if (!file_exists(public_path($dir))) {
            mkdir(public_path($dir), 0777, true);
        }
        
        $filename = Str::slug($docType) . '_' . Str::random(10) . '.pdf';
        $fullPath = public_path($dir . '/' . $filename);
        
        // Create a minimal dummy PDF (PDF header + minimal content)
        $pdfContent = "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n/Pages 2 0 R\n>>\nendobj\n2 0 obj\n<<\n/Type /Pages\n/Count 1\n/Kids [3 0 R]\n>>\nendobj\n3 0 obj\n<<\n/Type /Page\n/Parent 2 0 R\n/MediaBox [0 0 612 792]\n>>\nendobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000058 00000 n\n0000000115 00000 n\ntrailer\n<<\n/Size 4\n/Root 1 0 R\n>>\nstartxref\n190\n%%EOF";
        
        file_put_contents($fullPath, $pdfContent);
        
        return $dir . '/' . $filename;
    }

    /**
     * Generate score breakdown
     */
    private function generateScoreBreakdown(float $matchScore): array
    {
        return [
            ['label' => 'Market Opportunity', 'value' => rand(40, 100)],
            ['label' => 'Team Experience', 'value' => rand(40, 100)],
            ['label' => 'Financial Health', 'value' => rand(40, 100)],
            ['label' => 'Impact Alignment', 'value' => rand(40, 100)],
        ];
    }

    /**
     * Generate business name
     */
    private function generateBusinessName(int $index): string
    {
        $prefixes = ['Tech', 'Global', 'Smart', 'Eco', 'Digital', 'Innovation', 'Future', 'Next', 'Prime', 'Elite'];
        $suffixes = ['Solutions', 'Systems', 'Ventures', 'Enterprises', 'Group', 'Industries', 'Tech', 'Labs', 'Works', 'Dynamics'];
        
        return $prefixes[array_rand($prefixes)] . ' ' . $suffixes[array_rand($suffixes)] . ' ' . $index;
    }

    /**
     * Get random sector
     */
    private function getRandomSector(): string
    {
        $sectors = [
            'Technology',
            'Healthcare',
            'Agriculture',
            'Education',
            'Finance',
            'Manufacturing',
            'Retail',
            'Energy',
            'Transportation',
            'Real Estate'
        ];
        
        return $sectors[array_rand($sectors)];
    }

    /**
     * Get random location
     */
    private function getRandomLocation(): string
    {
        $locations = [
            'Nairobi, Kenya',
            'Mombasa, Kenya',
            'Kisumu, Kenya',
            'Eldoret, Kenya',
            'Nakuru, Kenya',
            'Lagos, Nigeria',
            'Accra, Ghana',
            'Dar es Salaam, Tanzania',
            'Kampala, Uganda',
            'Kigali, Rwanda'
        ];
        
        return $locations[array_rand($locations)];
    }

    /**
     * Get random stage
     */
    private function getRandomStage(): string
    {
        $stages = ['Idea', 'Seed', 'Early', 'Growth', 'Expansion'];
        return $stages[array_rand($stages)];
    }

    /**
     * Get random business type
     */
    private function getRandomBusinessType(): string
    {
        $types = ['Sole Proprietorship', 'Partnership', 'LLC', 'Corporation', 'Cooperative'];
        return $types[array_rand($types)];
    }

    /**
     * Generate Lorem Ipsum text
     */
    private function generateLoremIpsum(int $maxLength = 200): string
    {
        $loremWords = [
            'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit',
            'sed', 'do', 'eiusmod', 'tempor', 'incididunt', 'ut', 'labore', 'et', 'dolore',
            'magna', 'aliqua', 'enim', 'ad', 'minim', 'veniam', 'quis', 'nostrud',
            'exercitation', 'ullamco', 'laboris', 'nisi', 'aliquip', 'ex', 'ea', 'commodo',
            'consequat', 'duis', 'aute', 'irure', 'in', 'reprehenderit', 'voluptate',
            'velit', 'esse', 'cillum', 'fugiat', 'nulla', 'pariatur', 'excepteur', 'sint',
            'occaecat', 'cupidatat', 'non', 'proident', 'sunt', 'culpa', 'qui', 'officia',
            'deserunt', 'mollit', 'anim', 'id', 'est', 'laborum'
        ];
        
        $text = '';
        $wordCount = (int)($maxLength / 6); // Approximate words needed
        
        for ($i = 0; $i < $wordCount; $i++) {
            $text .= $loremWords[array_rand($loremWords)] . ' ';
            
            if (strlen($text) >= $maxLength) {
                break;
            }
        }
        
        return ucfirst(trim(substr($text, 0, $maxLength)));
    }
}
