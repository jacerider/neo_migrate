<?php

declare(strict_types=1);

namespace Drupal\neo_migrate;

/**
 * Builds a neo_color pallet ramp from one brand colour, as the pallet form does.
 *
 * A port of neo_color's own generator (src/js/neo-color.ts): each shade is
 * the brand colour mixed toward white or black on `chroma.scale(['#fff',
 * colour, '#000'])` at fixed points, and a shade is marked "dark" (its content
 * is drawn dark) when its CIEDE2000 distance from white is 35 or less. Same
 * input, same ramp as typing the colour into /admin/config/neo/color.
 */
final class PalletGenerator {

  /**
   * Where on the white → colour → black scale each shade sits, in thousandths.
   */
  private const RULES = [50 => 15, 100 => 35, 200 => 80, 300 => 160, 400 => 325, 500 => 500, 600 => 600, 700 => 700, 800 => 800, 900 => 900, 950 => 950];

  /**
   * The ramp for a brand colour.
   *
   * @return array<int, array{color: string, dark: string}>
   *   Shades keyed 50–950, in the shape neo_pallet config stores.
   */
  public function ramp(string $hex): array {
    $base = $this->rgb($hex);
    $white = [255, 255, 255];
    $black = [0, 0, 0];
    $shades = [];
    foreach (self::RULES as $shade => $rule) {
      $t = $rule / 1000;
      $color = $t <= 0.5 ? $this->mix($white, $base, $t / 0.5) : $this->mix($base, $black, ($t - 0.5) / 0.5);
      $shades[$shade] = [
        'color' => $this->hex($color),
        'dark' => $this->deltaE($color, $white) <= 35 ? '1' : '0',
      ];
    }
    return $shades;
  }

  /**
   * Linear RGB interpolation, rounded as chroma's hex() rounds.
   */
  private function mix(array $a, array $b, float $f): array {
    return array_map(static fn ($i) => $a[$i] + ($b[$i] - $a[$i]) * $f, [0, 1, 2]);
  }

  private function rgb(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
      $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
  }

  private function hex(array $rgb): string {
    return '#' . implode('', array_map(static fn ($c) => str_pad(dechex((int) round(max(0, min(255, $c)))), 2, '0', STR_PAD_LEFT), $rgb));
  }

  /**
   * CIEDE2000 colour difference, as chroma.deltaE() computes it.
   */
  private function deltaE(array $rgbA, array $rgbB): float {
    [$L1, $a1, $b1] = $this->lab(array_map(static fn ($c) => round($c), $rgbA));
    [$L2, $a2, $b2] = $this->lab($rgbB);
    $avgL = ($L1 + $L2) / 2;
    $C1 = sqrt($a1 ** 2 + $b1 ** 2);
    $C2 = sqrt($a2 ** 2 + $b2 ** 2);
    $avgC = ($C1 + $C2) / 2;
    $G = 0.5 * (1 - sqrt($avgC ** 7 / ($avgC ** 7 + 25 ** 7)));
    $a1p = $a1 * (1 + $G);
    $a2p = $a2 * (1 + $G);
    $C1p = sqrt($a1p ** 2 + $b1 ** 2);
    $C2p = sqrt($a2p ** 2 + $b2 ** 2);
    $avgCp = ($C1p + $C2p) / 2;
    $arctan1 = rad2deg(atan2($b1, $a1p));
    $arctan2 = rad2deg(atan2($b2, $a2p));
    $h1p = $arctan1 >= 0 ? $arctan1 : $arctan1 + 360;
    $h2p = $arctan2 >= 0 ? $arctan2 : $arctan2 + 360;
    $avgHp = abs($h1p - $h2p) > 180 ? ($h1p + $h2p + 360) / 2 : ($h1p + $h2p) / 2;
    $T = 1 - 0.17 * cos(deg2rad($avgHp - 30)) + 0.24 * cos(deg2rad(2 * $avgHp)) + 0.32 * cos(deg2rad(3 * $avgHp + 6)) - 0.2 * cos(deg2rad(4 * $avgHp - 63));
    $deltaHp = $h2p - $h1p;
    $deltaHp = abs($deltaHp) <= 180 ? $deltaHp : ($h2p <= $h1p ? $deltaHp + 360 : $deltaHp - 360);
    $deltaHp = 2 * sqrt($C1p * $C2p) * sin(deg2rad($deltaHp / 2));
    $deltaL = $L2 - $L1;
    $deltaCp = $C2p - $C1p;
    $sl = 1 + (0.015 * ($avgL - 50) ** 2) / sqrt(20 + ($avgL - 50) ** 2);
    $sc = 1 + 0.045 * $avgCp;
    $sh = 1 + 0.015 * $avgCp * $T;
    $deltaTheta = 30 * exp(-((($avgHp - 275) / 25) ** 2));
    $Rc = 2 * sqrt($avgCp ** 7 / ($avgCp ** 7 + 25 ** 7));
    $Rt = -$Rc * sin(deg2rad(2 * $deltaTheta));
    $result = sqrt(($deltaL / $sl) ** 2 + ($deltaCp / $sc) ** 2 + ($deltaHp / $sh) ** 2 + $Rt * ($deltaCp / $sc) * ($deltaHp / $sh));
    return max(0, min(100, $result));
  }

  /**
   * sRGB to CIE Lab (D65), as chroma converts.
   */
  private function lab(array $rgb): array {
    $lin = static function (float $c): float {
      $c /= 255;
      return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    [$r, $g, $b] = array_map($lin, $rgb);
    $x = (0.4124564 * $r + 0.3575761 * $g + 0.1804375 * $b) / 0.95047;
    $y = (0.2126729 * $r + 0.7151522 * $g + 0.0721750 * $b) / 1.0;
    $z = (0.0193339 * $r + 0.1191920 * $g + 0.9503041 * $b) / 1.08883;
    $f = static fn (float $t): float => $t > 0.008856452 ? $t ** (1 / 3) : $t / 0.12841855 + 0.137931034;
    [$fx, $fy, $fz] = [$f($x), $f($y), $f($z)];
    return [116 * $fy - 16, 500 * ($fx - $fy), 200 * ($fy - $fz)];
  }

}
