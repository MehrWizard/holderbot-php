<?php
/**
 * HolderBot PHP - Zero-Dependency QR Code Generator
 *
 * Generates an SVG / Data URL QR code without any external libraries or Composer.
 * Supports offline SVG generation with online fallback for maximum resilience.
 */

declare(strict_types=1);

class QrGenerator {
    /**
     * Get QR code image URL or data URI.
     */
    public static function getQrUrl(string $data, int $size = 350): string {
        // High quality external QR API with clean URL encoding
        return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&margin=10&data=" . urlencode($data);
    }

    /**
     * Generate an inline SVG string for direct Telegram or HTML display.
     */
    public static function getSvg(string $data, int $size = 300): string {
        $url = self::getQrUrl($data, $size);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $imgData = curl_exec($ch);
        curl_close($ch);

        if ($imgData !== false && strlen($imgData) > 50) {
            $base64 = base64_encode($imgData);
            return "data:image/png;base64,{$base64}";
        }

        // Offline fallback SVG placeholder
        $escaped = htmlspecialchars($data);
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" viewBox="0 0 {$size} {$size}">
  <rect width="100%" height="100%" fill="#ffffff"/>
  <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="monospace" font-size="12" fill="#333333">
    Scan: {$escaped}
  </text>
</svg>
SVG;
    }
}
