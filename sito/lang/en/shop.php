<?php

// Errori di vendita (App\Services\ShopException).
return [
    'not_available' => 'This ticket is not on sale right now.',
    'sold_out' => 'Sorry, there are no seats left.',
    'hold_too_short' => 'The reservation is too short.',
    'too_many_for_type' => 'Too many tickets of this type in one order.',
    'too_many_for_pool' => 'Too many seats for this session in one order.',
    'too_many_workshops' => 'You can book at most 2 workshops per order.',
    'workshop_needs_pass' => 'Workshops can only be bought together with, or after, a full pass.',
    'workshop_overlap' => 'Two of the workshops you chose take place at the same time.',
    'member_price_limit' => 'You already have a ticket of this type at the member price: please try again.',
    'pending_order_exists' => 'You already have an order in progress: please try again.',
    'discount_invalid' => 'This discount code is not valid.',
    'discount_exhausted' => 'This discount code has been used up.',
    'discount_user_limit' => 'You have already used this discount code.',
    'order_not_pending' => 'This order can no longer be paid.',
    'session_mismatch' => 'The payment does not belong to this order.',
    'already_member' => 'You are already a member.',
    'too_many_corrections' => 'This ticket has already been corrected 3 times.',
    'order_not_revocable' => 'Only a complimentary order that is still valid can be revoked.',
    'price_tier_overlap' => 'This tier overlaps another active tier of the event: change the dates or turn one off.',
];
