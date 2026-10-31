<?php

namespace Crater\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves Apple's domain association file for Stripe Apple Pay on embedded checkout.
 *
 * @see https://docs.stripe.com/apple-pay?platform=web#verify-domain
 */
class ApplePayDomainAssociationController
{
    public function __invoke(): Response|BinaryFileResponse
    {
        $paths = [
            public_path('.well-known/apple-developer-merchantid-domain-association'),
            storage_path('app/stripe/apple-developer-merchantid-domain-association'),
        ];

        foreach ($paths as $path) {
            if (is_readable($path)) {
                return response()->file($path, [
                    'Content-Type' => 'text/plain',
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
        }

        $inline = config('services.stripe.apple_pay_domain_association');
        if (is_string($inline) && $inline !== '') {
            return response($inline, 200, [
                'Content-Type' => 'text/plain',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        abort(404);
    }
}
