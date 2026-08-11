<?php
declare(strict_types=1);

/**
 * Server-side PDF helpers (Dompdf).
 */

function pdf_autoload(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('PDF library missing. Run composer install on the server.');
    }
    require_once $autoload;
    $loaded = true;
}

/**
 * Build and download a PDF from HTML. Exits on success.
 */
function pdf_download(string $html, string $filename, string $orientation = 'landscape', string $paper = 'A4'): void
{
    pdf_autoload();
    if (function_exists('set_time_limit')) {
        @set_time_limit(120);
    }

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isFontSubsettingEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper($paper, $orientation);
    $dompdf->render();

    $filename = preg_replace('/[^\w.\-]+/', '_', $filename) ?: 'report.pdf';
    if (strtolower(substr($filename, -4)) !== '.pdf') {
        $filename .= '.pdf';
    }

    // Clear any buffered output that would corrupt the PDF binary.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

/**
 * Escape for HTML used inside PDF templates.
 */
function pdf_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
