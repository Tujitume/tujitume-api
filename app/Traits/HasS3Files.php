<?php

namespace App\Traits;

use Illuminate\Support\Facades\Storage;

trait HasS3Files

{
    protected function privateFileFields(): array
    {
        return [];
    }

    protected function publicFileFields(): array
    {
        return [];
    }

    public function toArray()
    {
        $array = parent::toArray();

        // Only generate signed URLs for single model fetch, not collections
        //if (!$this->exists) return $array;

        foreach ($this->privateFileFields() as $field) {
            $array[$field . '_url'] = $this->$field
                ? Storage::disk('s3')->temporaryUrl($this->$field, now()->addMinutes(45))
                : null;
        }

        foreach ($this->publicFileFields() as $field) {
            $array[$field . '_url'] = $this->$field
                ? Storage::disk('s3')->url($this->$field)
                : null;
        }

        return $array;
    }
}

//{
//    public function s3Url(?string $path): ?string
//    {
//        if (!$path) {
//            return null;
//        }
//
//        return Storage::disk('s3')
//            ->temporaryUrl($path, now()->addMinutes(10));
//    }
//
//    protected function publicUrl(?string $path): ?string
//    {
//        return $path
//            ? Storage::disk('s3')->url($path)
//            : null;
//    }
//}
