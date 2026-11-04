<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A QR code, as SVG.
 *
 * Rendered on the server rather than in each client, because there are three
 * of them — the admin panel, the staff app and anything printed — and a QR
 * that scans on one but not another is a support call nobody can reproduce.
 * SVG rather than PNG so it stays sharp on a counter screen at any size and
 * needs no image extension installed.
 *
 * The markup is generated from a URL we construct, never from user input, so
 * it is safe to inline. It is still produced without an XML declaration so it
 * can be dropped straight into a page.
 */
class QrCode
{
    public function svg(string $text, int $size = 280): string
    {
        $writer = new Writer(new ImageRenderer(
            // Margin 1, not 0: a QR with no quiet zone fails to scan against a
            // busy background, which is exactly where this one lives.
            new RendererStyle($size, 1),
            new SvgImageBackEnd,
        ));

        $svg = $writer->writeString($text);

        // The declaration is only valid at the very start of a document, and
        // this one is going inside an HTML page.
        return preg_replace('/^<\?xml[^?]*\?>\s*/', '', $svg) ?? $svg;
    }
}
