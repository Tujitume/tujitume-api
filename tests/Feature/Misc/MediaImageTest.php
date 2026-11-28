<?php

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// Images on the public disk are served through the API (so PDF exports can draw them); nothing else is.
describe('media/image', function () {
    beforeEach(function () {
        Storage::fake('public');
        Storage::disk('public')->put('logos/a.png', UploadedFile::fake()->image('a.png', 20, 20)->getContent());
        Storage::disk('public')->put('docs/id.pdf', '%PDF-1.4 secret');
    });

    it('serves a public image, with or without the storage prefix', function () {
        $this->get('/api/v1/media/image?path=logos/a.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/api/v1/media/image?path=/storage/logos/a.png')->assertOk();
    });

    it('refuses files that are not images, missing files and paths that climb out', function () {
        $this->get('/api/v1/media/image?path=docs/id.pdf')->assertNotFound();
        $this->get('/api/v1/media/image?path=logos/missing.png')->assertNotFound();
        $this->get('/api/v1/media/image?path=../.env')->assertNotFound();
        $this->get('/api/v1/media/image')->assertNotFound();
    });
});
