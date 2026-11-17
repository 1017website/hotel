<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class SubscriptionUnavailable extends HttpException
{
    public function __construct(public readonly string $state, string $message, public readonly bool $publicBooking = false)
    {
        parent::__construct($state === 'maintenance' ? 503 : 423, $message);
    }
}
