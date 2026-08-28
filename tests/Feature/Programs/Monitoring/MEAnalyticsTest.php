<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Monitoring analytics', function () {
    it('requires authentication for overview analytics', function () {
        assertProgramUnauthenticated('GET', "monitoring/applications/{$this->application->id}/analytics/overview");
    });
    it('requires authentication for impact analytics', function () {
        assertProgramUnauthenticated('GET', "monitoring/applications/{$this->application->id}/analytics/impact");
    });
});
