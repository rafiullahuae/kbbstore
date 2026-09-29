<?php

/*
 * Lane SA2 — move the panel's new controls, so the "after" shots show the
 * sliders driving something rather than showing the same picture twice.
 *
 * Run inside the preview's environment:
 *
 *   sh tools/sa-preview.sh 8991
 *   KBB_PUBLIC_PATH=… DB_DATABASE=… php artisan tinker tools/sa2-move.php
 *
 * tools/sa2-shots.sh does both halves and is what the README points at.
 *
 * Every value below is a DELIBERATELY VISIBLE move — a mauve panel, a 30px
 * overhang, a 46px photograph — because the question these shots answer is
 * "does this control reach the page", and a two-pixel nudge answers it only for
 * somebody with the two files side by side.
 */
app(\App\Services\SetAppearance::class)->save([
    /* the panel */
    'p_panel_bg' => '#EFE6FA',
    'p_panel_r' => 6,
    'p_panel_pt' => 18,
    'p_panel_pe' => 22,
    'p_panel_pb' => 18,
    'p_panel_ps' => 34,
    'p_panel_r_m' => 4,
    'p_panel_ps_m' => 30,
    'p_panel_pe_m' => 16,

    /* the overhang, the ring and the drop */
    'p_over' => 24,
    'p_over_m' => 22,
    'p_ring' => 60,
    'p_ring_m' => 50,
    'p_ring_c' => '#FFF6DA',
    'p_sh_y' => 5,
    'p_sh_blur' => 12,
    'p_sh_a' => 55,

    /* the photograph */
    'p_photo' => 46,
    'p_photo_m' => 40,
    'p_radius' => 23,
    'p_radius_m' => 20,

    /* the rows: rules back ON, which the shipped box does not draw */
    'p_rule_on' => true,
    'p_line_c' => '#C9A0D8',
    'p_rowpad' => 9,
    'p_gap' => 18,

    /* the words */
    'p_name' => 160,
    'p_name_w' => 800,
    'p_name_lh' => 160,
    'p_name_c' => '#3A1C4A',
    'p_brand' => 13,
    'p_brand_c' => '#8A4FA8',
    'p_brand_ls' => 12,

    /* the quantity */
    'p_qty' => 16,
    'p_qty_c' => '#8A4FA8',

    /* the heading */
    'p_head_f' => 175,
    'p_head_w' => 800,
    'p_head_c' => '#3A1C4A',
    'p_head_gap' => 14,

    /* the fold */
    'p_fold_at' => 2,
    'p_more_c' => '#1C7A4A',
    'p_more' => 150,

    /* the footing */
    'p_footrule_c' => '#8A4FA8',
    'p_foot' => 15,
    'p_save_c' => '#B0004B',
]);

echo "moved\n";
