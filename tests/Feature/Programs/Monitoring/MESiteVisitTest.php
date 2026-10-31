<?php

use App\Http\Resources\Program\Monitoring\MESiteVisitResource;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\ReviewerOrder;
use Illuminate\Http\Request;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Monitoring site visits', function () {
    it('requires authentication to assign a site visit', function () {
        assertProgramUnauthenticated('POST', 'monitoring/checkpoints/999999/site-visit/assign');
    });

    it('returns the site visit order modification note and flagged items', function () {
        $visit = new MESiteVisit();
        $visit->setRelation('reviewerOrder', new ReviewerOrder([
            'modification_note' => 'Please correct the employment total.',
            'flagged_items' => [['field_id' => 'employment_total', 'label' => 'Employment total', 'note' => 'Use the verified count.']],
        ]));

        $order = (new MESiteVisitResource($visit))->toArray(Request::create('/'))['order'];

        expect($order['modification_note'])->toBe('Please correct the employment total.')
            ->and($order['flagged_items'])->toHaveCount(1)
            ->and($order['flagged_items'][0]['field_id'])->toBe('employment_total');
    });
});
