<?php
/* Lane RC: change the preview's one banner set between screenshots.

     php tools/rc-set.php <preview.sqlite> col=value [col=value ...]

   `cards.image_m=` (empty) clears every slide's phone picture; anything else
   is a banner_sets column. Plain PDO on the preview's own SQLite file --
   banner reads are uncached, so the next request draws the new values. */
$db = new PDO('sqlite:'.$argv[1]);
foreach (array_slice($argv, 2) as $pair) {
    [$col, $val] = explode('=', $pair, 2);
    if ($col === 'cards.image_m') {
        $db->exec("update banner_cards set image_m = ".$db->quote($val).", image_m_w = null, image_m_h = null");
        continue;
    }
    if (! preg_match('/^[a-z_]+$/', $col)) {
        exit("bad column $col\n");
    }
    $db->exec("update banner_sets set $col = ".($val === 'null' ? 'null' : $db->quote($val)));
}
print_r($db->query('select kind, slider_ratio, slider_ratio_m, slider_fit, slider_h, slider_h_m, bg_mode, bg_color from banner_sets')->fetchAll(PDO::FETCH_ASSOC));
