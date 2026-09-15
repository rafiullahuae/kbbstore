<?php

declare(strict_types=1);

use App\Support\Fils;
use App\Support\Money;

/*
|------------------------------------------------------------------------------
| Fils::parse — operator-typed AED to integer fils
|------------------------------------------------------------------------------
|
| The whole reason this class exists is that the obvious implementation is
| wrong, so the first test pins the wrongness rather than describing it.
*/

it('is not the naive float conversion, which is off by a fil', function () {
    // The bug, demonstrated on this machine, not asserted from memory.
    expect((int) (1.15 * 100))->toBe(114)
        ->and((int) (8.20 * 100))->toBe(819)
        ->and((int) (0.29 * 100))->toBe(28);

    // What the parser does instead.
    expect(Fils::parse('1.15'))->toBe(115)
        ->and(Fils::parse('8.20'))->toBe(820)
        ->and(Fils::parse('0.29'))->toBe(29);
});

it('parses whole dirhams', function () {
    expect(Fils::parse('0'))->toBe(0)
        ->and(Fils::parse('1'))->toBe(100)
        ->and(Fils::parse('20'))->toBe(2000)
        ->and(Fils::parse('199'))->toBe(19900)
        ->and(Fils::parse('1600'))->toBe(160000);
});

it('parses one and two decimal places', function () {
    expect(Fils::parse('12.5'))->toBe(1250)
        ->and(Fils::parse('12.50'))->toBe(1250)
        ->and(Fils::parse('12.05'))->toBe(1205)
        ->and(Fils::parse('0.01'))->toBe(1)
        ->and(Fils::parse('.5'))->toBe(50)
        ->and(Fils::parse('7.'))->toBe(700);
});

it('accepts the decoration an operator actually pastes in', function () {
    expect(Fils::parse(' 12.50 '))->toBe(1250)
        ->and(Fils::parse('1,299.99'))->toBe(129999)
        ->and(Fils::parse('AED 45'))->toBe(4500)
        ->and(Fils::parse('aed 45.25'))->toBe(4525)
        ->and(Fils::parse(Money::SYMBOL . '30'))->toBe(3000)
        ->and(Fils::parse('+18'))->toBe(1800);
});

it('handles a negative amount', function () {
    expect(Fils::parse('-5'))->toBe(-500)
        ->and(Fils::parse('-0.01'))->toBe(-1)
        ->and(Fils::parse('-1.15'))->toBe(-115);
});

it('refuses more precision than a fil can hold rather than rounding it away', function () {
    // 1.234 is 123.4 fils. Rounding would charge 123 and say nothing; an
    // operator who typed a third decimal is told instead.
    expect(Fils::parse('1.234'))->toBeNull()
        ->and(Fils::parse('0.005'))->toBeNull()
        ->and(Fils::parse('12.999'))->toBeNull();
});

it('refuses everything that is not a plain decimal number', function () {
    foreach (['', '   ', 'abc', '1.2.3', '1e3', '1 2', '--5', '-', '+', '.', '٢٥', '12٫5', 'NaN', 'Infinity'] as $bad) {
        expect(Fils::parse($bad))->toBeNull("expected {$bad} to be refused");
    }
});

it('refuses a float argument outright', function () {
    // A float reaching this method means the value has already been through
    // the conversion this class exists to avoid, so it is a bug at the call
    // site rather than something to salvage.
    expect(Fils::parse(1.15))->toBeNull();
});

it('takes an integer as whole dirhams', function () {
    expect(Fils::parse(12))->toBe(1200)
        ->and(Fils::parse(0))->toBe(0);
});

it('refuses an amount long enough to overflow', function () {
    expect(Fils::parse(str_repeat('9', 40)))->toBeNull();
});

it('round-trips through toDecimalString exactly', function () {
    foreach ([0, 1, 9, 10, 99, 100, 115, 820, 2000, 19900, 129999, -115] as $fils) {
        expect(Fils::parse(Fils::toDecimalString($fils)))->toBe($fils);
    }
});

it('formats fils as a decimal string with both places', function () {
    expect(Fils::toDecimalString(0))->toBe('0.00')
        ->and(Fils::toDecimalString(5))->toBe('0.05')
        ->and(Fils::toDecimalString(50))->toBe('0.50')
        ->and(Fils::toDecimalString(115))->toBe('1.15')
        ->and(Fils::toDecimalString(-115))->toBe('-1.15');
});

it('answers isValid in step with parse', function () {
    expect(Fils::isValid('12.50'))->toBeTrue()
        ->and(Fils::isValid('12.505'))->toBeFalse()
        ->and(Fils::isValid(''))->toBeFalse();
});

it('parseOr falls back only when the input cannot be parsed', function () {
    expect(Fils::parseOr('12.50', 999))->toBe(1250)
        ->and(Fils::parseOr('', 999))->toBe(999)
        ->and(Fils::parseOr(null, 999))->toBe(999)
        ->and(Fils::parseOr('nonsense', 999))->toBe(999)
        // Zero is a real answer, not a missing one.
        ->and(Fils::parseOr('0', 999))->toBe(0);
});
