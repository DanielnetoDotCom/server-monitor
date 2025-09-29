<?php
/**
 * Color Generator - Generate consistent colors for groups
 */

class ColorGenerator {
    
    /**
     * Generate a consistent color set based on a string
     * 
     * @param string $str Input string (group name)
     * @return array Array with 'bg', 'text', and 'border' color values
     */
    public static function generateColorsForString(string $str): array {
        // Generate a hash from the string
        $hash = md5($str);
        
        // Extract RGB values from hash
        $r = hexdec(substr($hash, 0, 2));
        $g = hexdec(substr($hash, 2, 2));
        $b = hexdec(substr($hash, 4, 2));
        
        // Make colors lighter for background
        $bgR = min(255, $r + 80);
        $bgG = min(255, $g + 80);
        $bgB = min(255, $b + 80);
        
        // Make colors darker for text
        $textR = max(0, $r - 80);
        $textG = max(0, $g - 80);
        $textB = max(0, $b - 80);
        
        // Convert back to hex
        $bgColor = sprintf('#%02x%02x%02x', $bgR, $bgG, $bgB);
        $textColor = sprintf('#%02x%02x%02x', $textR, $textG, $textB);
        $borderColor = sprintf('#%02x%02x%02x', $r, $g, $b);
        
        return [
            'bg' => $bgColor,
            'text' => $textColor,
            'border' => $borderColor
        ];
    }
    
    /**
     * Generate CSS for a group badge
     * 
     * @param string $groupName Group name
     * @return string CSS rules
     */
    public static function generateGroupBadgeCSS(string $groupName): string {
        $colors = self::generateColorsForString($groupName);
        $safeGroupName = preg_replace('/[^a-zA-Z0-9_-]/', '', $groupName);
        
        return ".group-badge-{$safeGroupName} {\n" .
               "    background-color: {$colors['bg']} !important;\n" .
               "    color: {$colors['text']} !important;\n" .
               "    border-color: {$colors['border']} !important;\n" .
               "}\n";
    }
}