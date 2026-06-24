<?php
namespace App\Service\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class FileUploadService
{
    public function saveFile($file, $path)
    {
        if (!$file) {
            return null;
        }

        if (!$file instanceof UploadedFile) {
            throw new \InvalidArgumentException('Invalid file upload');
        }

        // Normalize to array for both single & multiple uploads
        //$file = is_array($file) ? $file : [$file];

        // S3 bucket upload
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $filename = Str::uuid() . '.' . $extension;

        // Store in S3 (same folder structure)
        $storedPath = $file->storeAs($path, $filename, 's3');

        if(!$storedPath){
            throw new \Exception('Unable to store s3 image');
        }

        return $storedPath; // e.g. files/roundDocuments/1/2/uuid.pdf

        //Access $url = Storage::disk('s3')->temporaryUrl($path,now()->addMinutes(10));

    }

    /*
        $newName = hexdec(uniqid()) . '.' . strtolower($file->getClientOriginalExtension());
        $fullPath = $folder . '/' . $newName;

        // Move file
        $file->move($folder, $newName);
        $savedPath = $fullPath;
        return $savedPath;
        */

    //For Multiple Files
    public function saveFiles($files, $type = 'pre_release')
    {
        if (!$files) {
            return null;
        }

        $savedPaths = [];

        // Normalize to array for both single & multiple uploads
        $files = is_array($files) ? $files : [$files];

        foreach ($files as $file) {

            // Storage path: files/milestonePreRelease/{type}/YYYYMMDD/
            $folder = 'files/milestonePreRelease/' . $type . '/' . date('Ymd');

            if (!file_exists($folder)) {
                mkdir($folder, 0777, true);
            }

            $newName = hexdec(uniqid()) . '.' . strtolower($file->getClientOriginalExtension());
            $fullPath = $folder . '/' . $newName;

            // Move file
            $file->move($folder, $newName);

            $savedPaths[] = $fullPath;
        }

        return $savedPaths;
    }


}
