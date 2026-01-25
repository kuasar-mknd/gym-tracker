<?php

test('security headers are present', function () {
    $response = $this->get('/');

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');

    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), screen-wake-lock=(), gyroscope=(), magnetometer=(), accelerometer=()');
    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
