<?php
// Lane EB preview: run the shipped migration's up() against the preview DB,
// exactly what Core Updates does on apply. Preview only.
(require __DIR__.'/../database/migrations/2027_08_30_100000_rename_brand_everywhere.php')->up();
