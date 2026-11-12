<?php

namespace App\Models\Programs\Rounds;

use App\Models\Programs\ProgramApplication;
use App\Traits\HasS3Files;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ApplicationRoundResponse extends Model
{
    use HasFactory;
    use HasS3Files;

    protected function privateFileFields(): array
    {
        return ['file_path'];
    }

    protected $guarded = [];

    protected $casts = ['file_paths' => 'array'];

    /** Add temporary links for every uploaded file while retaining the stored S3 keys. */
    public function toArray()
    {
        $array = parent::toArray();
        $paths = $this->allFilePaths();

        $array['file_paths_url'] = array_map(
            fn (string $path) => Storage::disk('s3')->temporaryUrl(
                $path,
                now()->addMinutes(45),
                ['ResponseContentDisposition' => 'inline']
            ),
            $paths
        );

        return $array;
    }

    /** Every file of this answer (older answers only have `file_path`). */
    public function allFilePaths(): array
    {
        return $this->file_paths ?: ($this->file_path ? [$this->file_path] : []);
    }

    public function application()
    {
        return $this->belongsTo(ProgramApplication::class);
    }

    public function question()
    {
        return $this->belongsTo(RoundCustomQuestion::class, 'question_id');
    }
}
