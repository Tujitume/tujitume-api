<?php
namespace App\Service\File;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;


class ImageUploadService
{
    public function save($file, $path = 'images', $quality = 75)
    {
        if (!$file) {
            return null;
        }

        if (!$file instanceof UploadedFile) {
            throw new \InvalidArgumentException('Invalid file upload');
        }

        $baseUrl = config('filesystems.disks.s3.url');

        // Generate filename
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = Str::uuid() . '.' . $extension;

        // Read & process image
//        $image = Image::read($file);
//
//        // Resize if too large
//        if ($image->width() > 2000) {
//            $image->resize(2000, null, function ($constraint) {
//                $constraint->aspectRatio();
//                $constraint->upsize();
//            });
//        }
//
//        // Encode image (IMPORTANT: this is what you upload)
//        $imageStream = $image->encode($extension, $quality);

        // Upload to S3 as PUBLIC
        $fullPath = $path . '/' . $filename;

        // Upload to S3
        //Storage::disk('s3')->put($fullPath, $imageStream, 'public');
        $storedPath = Storage::disk('s3')->putFileAs($path, $file, $filename);

        if(!$storedPath){
            throw new \Exception('Unable to store s3 image');
        }
        return $baseUrl. '/'. $storedPath; // $fullPath


        // Access Storage::disk('s3')->url($path); or can save in DB

        /*
        $image->save($fullPath . '/'. $filename, $quality);
        return $site_url . $path . '/'. $filename;
        */
    }
}
