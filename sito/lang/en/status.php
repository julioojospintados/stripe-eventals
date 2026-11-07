<?php

// Stati di ordini e biglietti come li legge chi compra.
return [
    'pending' => 'Waiting for payment',
    'processing' => 'Payment in progress',
    'paid' => 'Paid',
    'expired' => 'Expired',
    'failed' => 'Payment failed',
    'needs_refund' => 'Being refunded',
    'cancelled' => 'Cancelled',
    'partially_refunded' => 'Partially refunded',
    'refunded' => 'Refunded',
    'needs_review' => 'Being checked',
    'ticket' => [
        'valid' => 'Valid',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
        'disputed' => 'Payment disputed',
    ],
];
