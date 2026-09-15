<?php

declare(strict_types=1);

// Proves the harness boots and the migration set runs end to end.
it('boots the application and runs the migration set', function () {
    expect(\Illuminate\Support\Facades\Schema::hasTable('orders'))->toBeTrue();
    expect(\Illuminate\Support\Facades\Schema::hasTable('order_items'))->toBeTrue();
    expect(\Illuminate\Support\Facades\Schema::hasTable('customers'))->toBeTrue();
});
