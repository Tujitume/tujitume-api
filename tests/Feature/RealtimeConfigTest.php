<?php

use Illuminate\Support\Facades\Config;

uses(Tests\TestCase::class);

describe('Real-time config for the frontend', function () {
    it('gives a browser only the public connection values', function () {
        Config::set('broadcasting.default', 'pusher');
        Config::set('broadcasting.connections.pusher.key', 'public-key');
        Config::set('broadcasting.connections.pusher.secret', 'the-secret');
        Config::set('broadcasting.connections.pusher.app_id', '12345');
        Config::set('broadcasting.connections.pusher.client', ['host' => 'ws.example.test', 'port' => 443, 'scheme' => 'https']);

        $response = $this->getJson('/api/v1/config/realtime')->assertOk();

        $response->assertExactJson([
            'enabled' => true, 'key' => 'public-key', 'cluster' => config('broadcasting.connections.pusher.options.cluster'),
            'host' => 'ws.example.test', 'port' => 443, 'scheme' => 'https',
        ]);
        expect($response->getContent())->not->toContain('the-secret')->not->toContain('12345');
    });

    it('says real-time is off when broadcasting is not Pusher or nothing is configured', function () {
        Config::set('broadcasting.default', 'log');
        $this->getJson('/api/v1/config/realtime')->assertOk()->assertExactJson(['enabled' => false]);

        Config::set('broadcasting.default', 'pusher');
        Config::set('broadcasting.connections.pusher.key', null);
        $this->getJson('/api/v1/config/realtime')->assertOk()->assertExactJson(['enabled' => false]);
    });
});
