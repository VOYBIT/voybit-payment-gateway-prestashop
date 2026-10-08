<?php

define('VOYBIT_PRESTASHOP_TEST', true);

require_once dirname(__DIR__) . '/voybit/classes/Amount.php';
require_once dirname(__DIR__) . '/voybit/classes/Checkout.php';
require_once dirname(__DIR__) . '/voybit/classes/Signature.php';
require_once dirname(__DIR__) . '/voybit/classes/RequestKey.php';

function voybit_prestashop_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$usd = VoybitAmount::from('25.00', 'usd');
voybit_prestashop_assert('25.00' === $usd['fiat_amount'], 'usd amount');
voybit_prestashop_assert('USD' === $usd['fiat_currency'], 'usd code');

$jpy = VoybitAmount::from('25', 'JPY');
voybit_prestashop_assert('25' === $jpy['fiat_amount'], 'jpy amount');

$bhd = VoybitAmount::from('1.234', 'BHD');
voybit_prestashop_assert('1.234' === $bhd['fiat_amount'], 'bhd amount');

$zeroTail = VoybitAmount::from('25.5000', 'USD');
voybit_prestashop_assert('25.50' === $zeroTail['fiat_amount'], 'trailing zero amount');

foreach (['25.501', '0.00', '0', '-1.00', 'USD', '25.00'] as $bad) {
    $threw = false;
    try {
        if ($bad === 'USD') {
            VoybitAmount::from('10.00', 'US');
        } elseif ($bad === '25.00') {
            VoybitAmount::from('25.00', 'usd1');
        } else {
            VoybitAmount::from($bad, 'USD');
        }
    } catch (InvalidArgumentException $error) {
        $threw = true;
        unset($error);
    }
    voybit_prestashop_assert($threw, 'rejected ' . $bad);
}

$id = 'nYVvXxsYGr5LZk8Dn7hU0Q';
voybit_prestashop_assert(
    'https://voybit.com/pay/' . $id === VoybitCheckout::canonical('https://voybit.com/pay/' . $id . '/'),
    'trailing slash'
);
foreach ([
    'http://voybit.com/pay/' . $id,
    'https://user:pass@voybit.com/pay/' . $id,
    'https://voybit.com/pay/' . $id . '?x=1',
    'https://voybit.com/pay/' . $id . '#pay',
    'https://voybit.com:443/pay/' . $id,
    'https://evil.example/pay/' . $id,
    'https://voybit.com.evil.example/pay/' . $id,
] as $url) {
    voybit_prestashop_assert(VoybitCheckout::canonical($url) === '', 'rejected url ' . $url);
}

$secret = 'whsec_example';
$delivery = 'delivery-1';
$timestamp = '1700000000';
$body = '{"type":"payment.paid","status":"paid"}';
$signature = 'v1=' . hash_hmac('sha256', $delivery . '.' . $timestamp . '.' . $body, $secret);
voybit_prestashop_assert(
    VoybitSignature::error($secret, $delivery, $timestamp, $signature, $body, 1700000000) === '',
    'signature'
);
voybit_prestashop_assert(
    VoybitSignature::error($secret, $delivery, $timestamp, $signature, $body . ' ', 1700000000) === 'mismatch',
    'tampered body'
);
voybit_prestashop_assert(
    VoybitSignature::error($secret, $delivery, $timestamp, $signature, $body, 1700000401) === 'expired',
    'expired signature'
);
voybit_prestashop_assert(
    VoybitSignature::error($secret, $delivery, $timestamp, 'v1=abcd', $body, 1700000000) === 'invalid',
    'short signature'
);

voybit_prestashop_assert(VoybitRequestKey::forCart(12) === 'prestashop:12', 'idempotency');
voybit_prestashop_assert(VoybitRequestKey::forCart(0) === '', 'idempotency zero');
voybit_prestashop_assert(VoybitRequestKey::forCart(-1) === '', 'idempotency negative');
voybit_prestashop_assert(VoybitAmount::validUuid('550e8400-e29b-41d4-a716-446655440000') === true, 'uuid');
voybit_prestashop_assert(VoybitAmount::validUuid('not-a-uuid') === false, 'bad uuid');

fwrite(STDOUT, "ok\n");
