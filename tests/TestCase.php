<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Gate;

abstract class TestCase extends BaseTestCase
{
    protected bool $shouldBypassAuthorization = true;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn ($user): ?bool => ($user && $this->shouldBypassAuthorization) ? true : null);
    }

    protected function disableAuthorizationBypass(): void
    {
        $this->shouldBypassAuthorization = false;
    }
}
