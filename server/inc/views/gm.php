<?php
/* Pomocné funkcie pre ponuky editorov (gmenu.js) – tabuľky aj dokumenty. */
/** položka ponuky */
function mi(string $act, string $icon, string $text, string $kbd = '', string $attr = ''): string {
    return '<li><button class="gm-i" data-gs="' . e($act) . '"' . $attr . '><i class="bi bi-' . $icon . '"></i><span>' . $text . '</span>' . ($kbd ? '<kbd>' . $kbd . '</kbd>' : '') . '</button></li>';
}
/** podponuka */
function sub(string $icon, string $text, string $inner, string $attr = ''): string {
    return '<li class="gm-sub"' . $attr . '><button class="gm-i"><i class="bi bi-' . $icon . '"></i><span>' . $text . '</span><i class="bi bi-caret-right-fill gm-arr"></i></button><ul class="gm-menu">' . $inner . '</ul></li>';
}
if (!defined('SEP')) define('SEP', '<li class="gm-sep"></li>');
function hd(string $t): string { return '<li class="gm-h">' . $t . '</li>'; }
