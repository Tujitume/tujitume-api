<?php

namespace Tests\Feature;

use App\Http\Resources\User\UserResource;
use App\Models\Auth\User;
use App\Models\Auth\UserType;
use App\Models\Organizations\Organization;
use App\Models\Organizations\workspace;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    public function test_it_maps_the_organization_workspace_schema(): void
    {
        $user = new User([
            'id' => 42,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'display_name' => 'Jane Org',
            'email' => 'jane@example.com',
            'phone' => '+254700000000',
            'image' => 'avatar.jpg',
            'gender' => 'Female',
            'dob' => '1990-01-01',
            'country' => 'KE',
            'city' => 'Nairobi',
            'website' => 'https://example.com',
            'completed_onboarding' => 1,
            'user_type_id' => 4,
            'organization_id' => 7,
        ]);

        $user->setRelation('user_type', new UserType([
            'id' => 4,
            'name' => 'organization',
        ]));

        $organization = new Organization([
            'id' => 7,
            'owner_user_id' => 42,
            'name' => 'Acme Org',
            'display_name' => 'Acme',
            'organization_type' => 'company',
            'status' => 'active',
        ]);

        $organization->setRelation('workspaces', collect([
            new workspace([
                'id' => 9,
                'organization_id' => 7,
                'name' => 'Acme Workspace',
                'slug' => 'acme',
                'subdomain' => 'acme',
                'workspace_status' => 'active',
            ]),
        ]));

        $user->setRelation('organization', $organization);

        $payload = (new UserResource($user))->resolve();

        $this->assertSame('Jane', $payload['first_name']);
        $this->assertSame(4, $payload['user_type_id']);
        $this->assertSame('organization', $payload['user_type']);
        $this->assertSame('Acme Org', $payload['organization']['name']);
        $this->assertCount(1, $payload['workspaces']);
        $this->assertArrayNotHasKey('grant_profile', $payload);
        $this->assertArrayNotHasKey('capital_profile', $payload);
    }
}
