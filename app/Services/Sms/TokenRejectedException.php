<?php

namespace App\Services\Sms;

/** The gateway refused a bearer token it issued earlier (expired or revoked). */
class TokenRejectedException extends SmsDeliveryException {}
