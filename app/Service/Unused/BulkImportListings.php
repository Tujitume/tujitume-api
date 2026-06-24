<?php
namespace App\Service\Unused;
use App\Models\Business\Listing;
use Illuminate\Support\Facades\Validator;

class BulkImportListings
{
    protected array $errors = [];
    protected array $insertData = [];

    /**
     * Import listings from a line-delimited JSON file
     *
     * @param string $filePath
     * @return array ['success_count' => int, 'errors' => array]
     */
    public function __construct()
    {

    }

    public function store(string $filePath): array
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $index => $line) {
            $item = json_decode($line, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->errors[$index] = ['Invalid JSON format'];
                continue;
            }

            $validator = Validator::make($item, $this->rules());

            if ($validator->fails()) {
                $this->errors[$index] = $validator->errors()->all();
                continue;
            }

            $this->insertData[] = $item;
        }

        if (!empty($this->insertData)) {
            Listing::insert($this->insertData);
        }

        return [
            'success_count' => count($this->insertData),
            'errors' => $this->errors
        ];
    }

    protected function rules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,id',
            'name' => 'required|string|max:300',
            'category' => 'required|string|max:255',
            'image' => 'required|string|max:255',
            'details' => 'required|string|max:1500',
            'location' => 'required|string|max:300',
            'lat' => 'required|string|max:100',
            'lng' => 'required|string|max:100',
            'contact' => 'nullable|string|max:255',
            'contact_mail' => 'nullable|email|max:100',
            'investment_needed' => 'nullable|integer',

            'share' => 'nullable|integer',
            'y_turnover' => 'required|string|max:255',
            'pin' => 'required|string|max:200',
            'identification' => 'required|string|max:200',
            'document' => 'nullable|string|max:200',
            'video' => 'nullable|string|max:200',
            'reason' => 'nullable|string|max:500',
            'stage' => 'nullable|string|max:70',
            'social_impact_areas' => 'nullable|array',
            'investors_fee' => 'nullable|integer',
            'yeary_fin_statement' => 'required|string|max:200',
            'id_no' => 'nullable|string|max:255',
            'tax_pin' => 'nullable|string|max:255',
        ];
    }

}
