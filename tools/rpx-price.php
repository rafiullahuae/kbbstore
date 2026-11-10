<?php
// Lane RP preview: set the mist's price (fils) from RP_PRICE, as the owner's editor would.
$p = \App\Models\Product::where('slug', 'rp-anua-pdrn-capsule-mist-mini')->firstOrFail();
$p->price = (int) getenv('RP_PRICE');
$p->save();
echo 'price=' . $p->price . ' id=' . $p->id . "\n";
