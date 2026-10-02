<?php
/*
 * Seed the Lane RF preview (desktop section order): Lane QA's product fixture
 * (a toner with five reviews, a set, a variable product) plus Lane RB's
 * re-filed catalogue with "Buy these together" switched on, so every block
 * below the two columns -- Buy these together, Product details, Reviews, You
 * may also like -- is drawn on the toner's page.
 *
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
require __DIR__.'/qa-seed.php';
require __DIR__.'/rb-seed.php';

echo "rf seed done\n";
