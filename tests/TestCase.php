<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Contracts\Auth\Authenticatable;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);
        // Switching test users represents a new login, not a reused password-bound session.
        return $this->withSession(['password_hash_'.($guard ?? config('auth.defaults.guard')) => null]);
    }
}
