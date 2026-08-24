<?php

return [
    /*
     * Public registration availability checks. Keep this allow-list limited to
     * fields that are safe to disclose and directly relevant to registration.
     */
    'fields' => [
        'email' => [
            'table' => 'users',
            'column' => 'email',
            'rules' => ['required', 'email:rfc,dns', 'max:255'],
            'normalizer' => 'lowercase_trim',
        ],
        'phone' => [
            'table' => 'users',
            'column' => 'phone',
            'rules' => ['required', 'string', 'max:50'],
            'normalizer' => 'trim',
        ],
        'workspace_slug' => [
            'table' => 'workspaces',
            'column' => 'slug',
            'rules' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'normalizer' => 'lowercase_trim',
        ],
        'workspace_subdomain' => [
            'table' => 'workspaces',
            'column' => 'subdomain',
            'rules' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'normalizer' => 'lowercase_trim',
        ],
        'organization_email' => [
            'table' => 'organizations',
            'column' => 'email',
            'rules' => ['required', 'email:rfc,dns', 'max:255'],
            'normalizer' => 'lowercase_trim',
        ],
        'organization_phone' => [
            'table' => 'organizations',
            'column' => 'phone',
            'rules' => ['required', 'string', 'max:50'],
            'normalizer' => 'trim',
        ],
    ],
];
