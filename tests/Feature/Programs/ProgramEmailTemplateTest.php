<?php

use App\Http\Resources\ApiResponseResource;

uses(Tests\TestCase::class);

it('standardizes email template mutation errors', function () { expect(ApiResponseResource::error('Not found.', null, 404)->getData(true)['success'])->toBeFalse(); });
