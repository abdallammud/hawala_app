<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Production insists on 2FA for everyone. The ~400 older tests sign
        // users in directly, so the default is off here; the 2FA tests turn
        // it back on with Settings::set('security.two_factor_required', true).
        config(['hawala.security.two_factor_required' => false]);
    }
}
