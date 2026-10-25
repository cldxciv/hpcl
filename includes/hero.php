<?php
declare(strict_types=1);
const HERO_DEFAULTS = ['enabled' => '1', 'alt' => '', 'subheading' => '', 'description' => '', 'button_label' => '', 'button_url' => '', 'new_tab' => '0', 'content_position' => 'bottom-center', 'image_position' => 'center', 'overlay' => '1', 'interval' => ''];
const HERO_POSITIONS = ['top-left', 'top-center', 'top-right', 'middle-left', 'middle-center', 'middle-right', 'bottom-left', 'bottom-center', 'bottom-right'];
function heroOptions(array $slide): array {
    $stored = json_decode((string) ($slide['options'] ?? '{}'), true);
    if (!is_array($stored)) return HERO_DEFAULTS;
    $options = HERO_DEFAULTS;
    foreach (HERO_DEFAULTS as $key => $default) {
        if (isset($stored[$key]) && is_scalar($stored[$key])) $options[$key] = (string) $stored[$key];
    }
    return $options;
}
