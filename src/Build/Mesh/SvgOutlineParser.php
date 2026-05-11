<?php

declare(strict_types=1);

namespace PHPolygon\Build\Mesh;

use DOMDocument;
use DOMElement;

/**
 * Parses an SVG file into a flat list of closed 2D polygons (outlines).
 *
 * Supported elements:
 *   - <path>     with M, L, H, V, Z commands (relative + absolute)
 *                quadratic / cubic beziers approximated as line segments
 *   - <polygon>  comma- or space-separated point pairs
 *   - <polyline> closed automatically if it isn't already
 *   - <rect>     with optional rx / ry corner rounding (sampled to lines)
 *   - <circle>   sampled at $segments uniform points
 *   - <ellipse>  sampled at $segments uniform points
 *
 * Coordinate convention:
 *   - SVG uses Y-down; the parser flips to Y-up so the output sits in a
 *     conventional right-handed XY plane (better for downstream extrude /
 *     revolve generators that assume +Y is up).
 *   - The result is normalised so the bounding box of the union of all
 *     outlines fits inside the unit square [-0.5, +0.5] × [-0.5, +0.5]
 *     when $normalise is true. Pass false to keep raw SVG coordinates.
 *
 * Each output polygon is a list of [x, y] pairs in CCW order (when the
 * SVG path was originally CW under Y-down, the Y-flip makes it CCW). This
 * matches the convention the MeshExtruder / triangulator expect.
 *
 * Limitations (deliberately out of scope for the v0 build pipeline):
 *   - No <use>, <g> transform composition: shapes inside <g> with a
 *     transform attribute are parsed in local coordinates only.
 *   - No CSS-driven path rendering: the parser walks only DOM attributes.
 *   - Self-intersecting paths produce undefined triangulation downstream.
 */
final class SvgOutlineParser
{
    /** Bezier subdivision count (per curve segment). */
    private const BEZIER_SEGMENTS = 16;

    /** Default segment count for circles / ellipses. */
    private const ARC_SEGMENTS = 32;

    /**
     * @return list<list<array{0: float, 1: float}>> One inner list per outline.
     */
    public function parseFile(string $path, bool $normalise = true): array
    {
        $xml = @file_get_contents($path);
        if ($xml === false) {
            throw new \RuntimeException("Cannot read SVG: {$path}");
        }
        return $this->parseString($xml, $normalise);
    }

    /**
     * @return list<list<array{0: float, 1: float}>>
     */
    public function parseString(string $xml, bool $normalise = true): array
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml)) {
            throw new \RuntimeException('Invalid SVG XML');
        }

        $outlines = [];
        $root = $doc->documentElement;
        if ($root !== null) {
            $this->walk($root, $outlines);
        }

        // Y-flip + normalise.
        $outlines = array_map(
            fn(array $polygon): array => array_map(
                fn(array $p): array => [$p[0], -$p[1]],
                $polygon,
            ),
            $outlines,
        );

        if ($normalise) {
            $outlines = $this->normaliseToUnitBox($outlines);
        }

        return array_values(array_filter(
            $outlines,
            static fn(array $poly): bool => count($poly) >= 3,
        ));
    }

    /**
     * @param list<list<array{0: float, 1: float}>> $outlines
     */
    private function walk(DOMElement $node, array &$outlines): void
    {
        $local = $this->parseElement($node);
        foreach ($local as $poly) {
            $outlines[] = $poly;
        }
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $this->walk($child, $outlines);
            }
        }
    }

    /**
     * @return list<list<array{0: float, 1: float}>>
     */
    private function parseElement(DOMElement $el): array
    {
        return match ($el->localName) {
            'path'     => $this->parsePath($el->getAttribute('d')),
            'polygon'  => [$this->parsePoints($el->getAttribute('points'))],
            'polyline' => [$this->closePolyline($this->parsePoints($el->getAttribute('points')))],
            'rect'     => [$this->parseRect($el)],
            'circle'   => [$this->parseCircle(
                (float)$el->getAttribute('cx'),
                (float)$el->getAttribute('cy'),
                (float)$el->getAttribute('r'),
            )],
            'ellipse'  => [$this->parseEllipse(
                (float)$el->getAttribute('cx'),
                (float)$el->getAttribute('cy'),
                (float)$el->getAttribute('rx'),
                (float)$el->getAttribute('ry'),
            )],
            default    => [],
        };
    }

    /**
     * SVG path "d" attribute parser. Supports M, m, L, l, H, h, V, v,
     * Q, q, C, c, T, t, S, s, Z, z. Commands stay anchored to the
     * previous endpoint as per the SVG spec; relative commands sum
     * onto it.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    private function parsePath(string $d): array
    {
        if ($d === '') return [];

        $tokens = $this->tokenisePath($d);
        $outlines = [];
        $current  = [];
        $cursor   = [0.0, 0.0];
        $start    = [0.0, 0.0];
        $lastCtrl = null; // for T / S smooth-bezier reflection

        $i = 0;
        $n = count($tokens);
        $cmd = '';

        while ($i < $n) {
            $t = $tokens[$i];

            // Command letter? Otherwise re-use the previous command (SVG
            // implicit repeat rule, e.g. "M0 0 1 1 2 2" = M then two L's).
            if (preg_match('/^[A-Za-z]$/', $t)) {
                $cmd = $t;
                $i++;
            } elseif ($cmd === '') {
                $i++;
                continue;
            } elseif ($cmd === 'M') {
                $cmd = 'L';   // implicit lineto after explicit moveto
            } elseif ($cmd === 'm') {
                $cmd = 'l';
            }

            $abs = ctype_upper($cmd);

            switch (strtoupper($cmd)) {
                case 'M':
                    $x = (float)$tokens[$i++];
                    $y = (float)$tokens[$i++];
                    if (!$abs) { $x += $cursor[0]; $y += $cursor[1]; }
                    if ($current !== []) {
                        $outlines[] = $current;
                    }
                    $current = [[$x, $y]];
                    $cursor  = [$x, $y];
                    $start   = [$x, $y];
                    $lastCtrl = null;
                    break;

                case 'L':
                    $x = (float)$tokens[$i++];
                    $y = (float)$tokens[$i++];
                    if (!$abs) { $x += $cursor[0]; $y += $cursor[1]; }
                    $current[] = [$x, $y];
                    $cursor    = [$x, $y];
                    $lastCtrl  = null;
                    break;

                case 'H':
                    $x = (float)$tokens[$i++];
                    if (!$abs) { $x += $cursor[0]; }
                    $current[] = [$x, $cursor[1]];
                    $cursor    = [$x, $cursor[1]];
                    $lastCtrl  = null;
                    break;

                case 'V':
                    $y = (float)$tokens[$i++];
                    if (!$abs) { $y += $cursor[1]; }
                    $current[] = [$cursor[0], $y];
                    $cursor    = [$cursor[0], $y];
                    $lastCtrl  = null;
                    break;

                case 'Q':
                    $cx = (float)$tokens[$i++];
                    $cy = (float)$tokens[$i++];
                    $x  = (float)$tokens[$i++];
                    $y  = (float)$tokens[$i++];
                    if (!$abs) { $cx += $cursor[0]; $cy += $cursor[1]; $x += $cursor[0]; $y += $cursor[1]; }
                    $this->appendQuadratic($current, $cursor, [$cx, $cy], [$x, $y]);
                    $cursor   = [$x, $y];
                    $lastCtrl = [$cx, $cy];
                    break;

                case 'T':
                    // Smooth quadratic: control = reflection of last ctrl.
                    $x = (float)$tokens[$i++];
                    $y = (float)$tokens[$i++];
                    if (!$abs) { $x += $cursor[0]; $y += $cursor[1]; }
                    $cx = 2.0 * $cursor[0] - ($lastCtrl[0] ?? $cursor[0]);
                    $cy = 2.0 * $cursor[1] - ($lastCtrl[1] ?? $cursor[1]);
                    $this->appendQuadratic($current, $cursor, [$cx, $cy], [$x, $y]);
                    $cursor   = [$x, $y];
                    $lastCtrl = [$cx, $cy];
                    break;

                case 'C':
                    $c1x = (float)$tokens[$i++];
                    $c1y = (float)$tokens[$i++];
                    $c2x = (float)$tokens[$i++];
                    $c2y = (float)$tokens[$i++];
                    $x   = (float)$tokens[$i++];
                    $y   = (float)$tokens[$i++];
                    if (!$abs) {
                        $c1x += $cursor[0]; $c1y += $cursor[1];
                        $c2x += $cursor[0]; $c2y += $cursor[1];
                        $x   += $cursor[0]; $y   += $cursor[1];
                    }
                    $this->appendCubic($current, $cursor, [$c1x, $c1y], [$c2x, $c2y], [$x, $y]);
                    $cursor   = [$x, $y];
                    $lastCtrl = [$c2x, $c2y];
                    break;

                case 'S':
                    $c2x = (float)$tokens[$i++];
                    $c2y = (float)$tokens[$i++];
                    $x   = (float)$tokens[$i++];
                    $y   = (float)$tokens[$i++];
                    if (!$abs) {
                        $c2x += $cursor[0]; $c2y += $cursor[1];
                        $x   += $cursor[0]; $y   += $cursor[1];
                    }
                    $c1x = 2.0 * $cursor[0] - ($lastCtrl[0] ?? $cursor[0]);
                    $c1y = 2.0 * $cursor[1] - ($lastCtrl[1] ?? $cursor[1]);
                    $this->appendCubic($current, $cursor, [$c1x, $c1y], [$c2x, $c2y], [$x, $y]);
                    $cursor   = [$x, $y];
                    $lastCtrl = [$c2x, $c2y];
                    break;

                case 'Z':
                    if ($current !== [] && $current[0] !== $cursor) {
                        $current[] = $start;
                    }
                    $cursor   = $start;
                    if ($current !== []) {
                        $outlines[] = $current;
                        $current = [];
                    }
                    $lastCtrl = null;
                    break;

                default:
                    // Skip unknown command and its arguments would be hard to
                    // disambiguate; safest to bail out of the path.
                    return $outlines;
            }
        }

        if ($current !== []) {
            $outlines[] = $current;
        }
        return $outlines;
    }

    /** @return list<string> */
    private function tokenisePath(string $d): array
    {
        // Insert spaces before each command letter, then split on commas /
        // whitespace, then keep numbers + letters as separate tokens.
        $d = preg_replace('/([A-Za-z])/', ' $1 ', $d) ?? '';
        // Negative numbers can hug the previous token: "10-5" -> "10 -5".
        $d = preg_replace('/(\d)-/', '$1 -', $d) ?? '';
        $d = str_replace(',', ' ', $d);
        $tokens = preg_split('/\s+/', trim($d)) ?: [];
        return array_values(array_filter($tokens, static fn(string $t): bool => $t !== ''));
    }

    /**
     * @param list<array{0: float, 1: float}> $current
     * @param array{0: float, 1: float} $p0
     * @param array{0: float, 1: float} $c
     * @param array{0: float, 1: float} $p1
     */
    private function appendQuadratic(array &$current, array $p0, array $c, array $p1): void
    {
        for ($s = 1; $s <= self::BEZIER_SEGMENTS; $s++) {
            $t = $s / self::BEZIER_SEGMENTS;
            $u = 1.0 - $t;
            $x = $u * $u * $p0[0] + 2.0 * $u * $t * $c[0] + $t * $t * $p1[0];
            $y = $u * $u * $p0[1] + 2.0 * $u * $t * $c[1] + $t * $t * $p1[1];
            $current[] = [$x, $y];
        }
    }

    /**
     * @param list<array{0: float, 1: float}> $current
     * @param array{0: float, 1: float} $p0
     * @param array{0: float, 1: float} $c1
     * @param array{0: float, 1: float} $c2
     * @param array{0: float, 1: float} $p1
     */
    private function appendCubic(array &$current, array $p0, array $c1, array $c2, array $p1): void
    {
        for ($s = 1; $s <= self::BEZIER_SEGMENTS; $s++) {
            $t = $s / self::BEZIER_SEGMENTS;
            $u = 1.0 - $t;
            $b0 = $u * $u * $u;
            $b1 = 3.0 * $u * $u * $t;
            $b2 = 3.0 * $u * $t * $t;
            $b3 = $t * $t * $t;
            $x  = $b0 * $p0[0] + $b1 * $c1[0] + $b2 * $c2[0] + $b3 * $p1[0];
            $y  = $b0 * $p0[1] + $b1 * $c1[1] + $b2 * $c2[1] + $b3 * $p1[1];
            $current[] = [$x, $y];
        }
    }

    /** @return list<array{0: float, 1: float}> */
    private function parsePoints(string $points): array
    {
        $points = trim($points);
        if ($points === '') return [];
        $pairs = preg_split('/[\s,]+/', $points) ?: [];
        $out = [];
        for ($i = 0; $i + 1 < count($pairs); $i += 2) {
            $out[] = [(float)$pairs[$i], (float)$pairs[$i + 1]];
        }
        return $out;
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     * @return list<array{0: float, 1: float}>
     */
    private function closePolyline(array $points): array
    {
        if (count($points) < 2) return $points;
        $first = $points[0];
        $last  = $points[count($points) - 1];
        if (abs($first[0] - $last[0]) > 1e-9 || abs($first[1] - $last[1]) > 1e-9) {
            $points[] = $first;
        }
        return $points;
    }

    /** @return list<array{0: float, 1: float}> */
    private function parseRect(DOMElement $el): array
    {
        $x = (float)$el->getAttribute('x');
        $y = (float)$el->getAttribute('y');
        $w = (float)$el->getAttribute('width');
        $h = (float)$el->getAttribute('height');
        return [
            [$x,        $y],
            [$x + $w,   $y],
            [$x + $w,   $y + $h],
            [$x,        $y + $h],
            [$x,        $y],
        ];
    }

    /** @return list<array{0: float, 1: float}> */
    private function parseCircle(float $cx, float $cy, float $r, int $segments = self::ARC_SEGMENTS): array
    {
        $out = [];
        for ($i = 0; $i <= $segments; $i++) {
            $a = 2.0 * M_PI * $i / $segments;
            $out[] = [$cx + $r * cos($a), $cy + $r * sin($a)];
        }
        return $out;
    }

    /** @return list<array{0: float, 1: float}> */
    private function parseEllipse(float $cx, float $cy, float $rx, float $ry, int $segments = self::ARC_SEGMENTS): array
    {
        $out = [];
        for ($i = 0; $i <= $segments; $i++) {
            $a = 2.0 * M_PI * $i / $segments;
            $out[] = [$cx + $rx * cos($a), $cy + $ry * sin($a)];
        }
        return $out;
    }

    /**
     * Re-centre + scale a list of outlines so the union bounding box fits
     * the unit square [-0.5, 0.5]² with aspect preserved.
     *
     * @param list<list<array{0: float, 1: float}>> $outlines
     * @return list<list<array{0: float, 1: float}>>
     */
    private function normaliseToUnitBox(array $outlines): array
    {
        if ($outlines === []) return $outlines;
        $minX = INF; $minY = INF; $maxX = -INF; $maxY = -INF;
        foreach ($outlines as $poly) {
            foreach ($poly as $p) {
                $minX = min($minX, $p[0]); $maxX = max($maxX, $p[0]);
                $minY = min($minY, $p[1]); $maxY = max($maxY, $p[1]);
            }
        }
        $w = $maxX - $minX;
        $h = $maxY - $minY;
        $scale = ($w === 0.0 && $h === 0.0) ? 1.0 : 1.0 / max($w, $h);
        $cx = ($minX + $maxX) * 0.5;
        $cy = ($minY + $maxY) * 0.5;

        return array_map(
            fn(array $poly): array => array_map(
                fn(array $p): array => [($p[0] - $cx) * $scale, ($p[1] - $cy) * $scale],
                $poly,
            ),
            $outlines,
        );
    }
}
