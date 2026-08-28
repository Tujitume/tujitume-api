<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Monitoring submissions', function () {
    it('requires authentication to submit evidence', function () {
        assertProgramUnauthenticated('POST', 'monitoring/checkpoints/999999/submit');
    });
});
