<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Monitoring site visits', function () {
    it('requires authentication to assign a site visit', function () {
        assertProgramUnauthenticated('POST', 'monitoring/checkpoints/999999/site-visit/assign');
    });
});
