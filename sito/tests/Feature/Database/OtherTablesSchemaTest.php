<?php

// Regole del database delle tabelle nuove della Fase 1: codici sconto, consensi, registro audit.

use App\Enums\DiscountType;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\DiscountCode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('rifiuta un codice sconto malformato', function (array $attributes, string $constraint) {
    expect(fn () => DiscountCode::factory()->create($attributes))->toThrow(QueryException::class, $constraint);
})->with([
    'percentuale oltre 100' => [['percent_off' => 120], 'discount_codes_shape'],
    'importo con percentuale' => [['discount_type' => DiscountType::Amount, 'amount_off_cents' => 500], 'discount_codes_shape'],
    'utilizzi oltre il massimo' => [['max_redemptions' => 1, 'redeemed_count' => 2], 'discount_codes_redemptions_valid'],
    'caratteri non ammessi' => [['code' => 'sconto 10%'], 'discount_codes_code_format'],
]);

it('salva il codice sconto in maiuscolo', function () {
    expect(DiscountCode::factory()->create(['code' => ' early-10 '])->code)->toBe('EARLY-10');
});

it('tiene consensi e registro audit solo in aggiunta', function () {
    requireTrigger('consents_no_update');
    requireTrigger('audit_log_no_delete');

    $consent = Consent::create([
        'email' => 'ana@example.test', 'granted' => true, 'source' => 'checkout', 'text_version' => '2026-11',
    ]);
    $log = AuditLog::record('test.done');

    expect(fn () => DB::table('consents')->where('id', $consent->id)->update(['granted' => false]))
        ->toThrow(QueryException::class, 'consents_append_only')
        ->and(fn () => DB::table('audit_log')->where('id', $log->id)->delete())
        ->toThrow(QueryException::class, 'audit_log_append_only');
});
