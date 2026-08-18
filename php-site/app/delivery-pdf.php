<?php
declare(strict_types=1);

/**
 * Minimal, dependency-free PDF renderer for delivery sheets.
 * It embeds the original JPEG product photos so the courier can identify each model at a glance.
 */
final class DeliveryPdf {
    private array $pages = [];
    private int $currentPage = -1;
    private array $images = [];

    public function addPage(): void {
        $this->pages[] = '';
        $this->currentPage = count($this->pages) - 1;
    }

    public function fill(float $r, float $g, float $b): void {
        $this->write(sprintf("%.3F %.3F %.3F rg\n", $r, $g, $b));
    }

    public function stroke(float $r, float $g, float $b, float $width = 1): void {
        $this->write(sprintf("%.3F w %.3F %.3F %.3F RG\n", $width, $r, $g, $b));
    }

    public function rect(float $x, float $y, float $width, float $height, bool $filled = true): void {
        $this->write(sprintf("%.2F %.2F %.2F %.2F re %s\n", $x, $y, $width, $height, $filled ? 'f' : 'S'));
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void {
        $this->write(sprintf("%.2F %.2F m %.2F %.2F l S\n", $x1, $y1, $x2, $y2));
    }

    public function text(float $x, float $y, float $size, string $text, array $rgb = [0.08, 0.16, 0.25]): void {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($encoded === false) $encoded = $text;
        $escaped = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $encoded);
        $this->write(sprintf(
            "BT /F1 %.2F Tf %.3F %.3F %.3F rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $size,
            $rgb[0],
            $rgb[1],
            $rgb[2],
            $x,
            $y,
            $escaped
        ));
    }

    public function imageJpeg(string $path, float $x, float $y, float $width, float $height): void {
        if (!is_file($path)) return;
        $key = sha1($path);
        if (!isset($this->images[$key])) {
            $size = @getimagesize($path);
            $bytes = @file_get_contents($path);
            if (!$size || !$bytes || ($size[2] ?? null) !== IMAGETYPE_JPEG) return;
            $channels = (int) ($size['channels'] ?? 3);
            $this->images[$key] = [
                'name' => 'Im' . (count($this->images) + 1),
                'path' => $path,
                'bytes' => $bytes,
                'width' => (int) $size[0],
                'height' => (int) $size[1],
                'color' => $channels === 4 ? '/DeviceCMYK' : '/DeviceRGB',
            ];
        }
        $name = $this->images[$key]['name'];
        $this->write(sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $width, $height, $x, $y, $name));
    }

    public function output(): string {
        if (!$this->pages) $this->addPage();
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        $nextObject = 4;
        $imageRefs = [];
        foreach ($this->images as $key => $image) {
            $objectId = $nextObject++;
            $imageRefs[$image['name']] = $objectId;
            $objects[$objectId] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $image['width'],
                $image['height'],
                $image['color'],
                strlen($image['bytes']),
                $image['bytes']
            );
        }

        $pageRefs = [];
        $xObjects = '';
        foreach ($imageRefs as $name => $objectId) $xObjects .= '/' . $name . ' ' . $objectId . " 0 R ";
        foreach ($this->pages as $content) {
            $contentId = $nextObject++;
            $pageId = $nextObject++;
            $objects[$contentId] = '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . "endstream";
            $resources = '<< /Font << /F1 3 0 R >>' . ($xObjects !== '' ? ' /XObject << ' . $xObjects . '>>' : '') . ' >>';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources ' . $resources . ' /Contents ' . $contentId . ' 0 R >>';
            $pageRefs[] = $pageId;
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageRefs)) . '] /Count ' . count($pageRefs) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach (array_keys($objects) as $id) $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        $pdf .= 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }

    private function write(string $content): void {
        if ($this->currentPage < 0) $this->addPage();
        $this->pages[$this->currentPage] .= $content;
    }
}

function delivery_pdf_lines(string $value, int $maxCharacters = 48): array {
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '') return [''];
    $words = explode(' ', $value);
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        $length = function_exists('mb_strlen') ? mb_strlen($candidate) : strlen($candidate);
        if ($length > $maxCharacters && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $candidate;
        }
    }
    if ($line !== '') $lines[] = $line;
    return $lines;
}

function delivery_pdf_crop(string $value, int $maxCharacters): string {
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length <= $maxCharacters) return $value;
    $slice = function_exists('mb_substr') ? mb_substr($value, 0, $maxCharacters - 1) : substr($value, 0, $maxCharacters - 1);
    return rtrim($slice) . '…';
}

function delivery_sheet_pdf(array $orders, string $date, string $publicRoot): string {
    $pdf = new DeliveryPdf();
    $perPage = 4;
    $total = count($orders);
    foreach ($orders as $index => $order) {
        if ($index % $perPage === 0) {
            $pdf->addPage();
            $pdf->fill(0.05, 0.12, 0.22);
            $pdf->rect(0, 790, 595, 52);
            $pdf->text(38, 815, 19, 'L’HORLOGER', [1, 1, 1]);
            $pdf->text(38, 798, 8, 'BORDEREAU LIVREUR', [0.77, 0.85, 0.93]);
            $pdf->text(385, 815, 10, 'COMMANDES DU JOUR', [1, 1, 1]);
            $pdf->text(416, 799, 10, date('d/m/Y', strtotime($date)), [0.77, 0.85, 0.93]);
            $pdf->text(38, 770, 9, 'Paiement à la réception · Vérifier le modèle, la couleur et les coordonnées avant départ.', [0.32, 0.40, 0.50]);
        }

        $slot = $index % $perPage;
        $top = 742 - ($slot * 166);
        $bottom = $top - 150;
        $pdf->fill(1, 1, 1);
        $pdf->rect(32, $bottom, 531, 150);
        $pdf->stroke(0.82, 0.86, 0.91, 0.8);
        $pdf->rect(32, $bottom, 531, 150, false);
        $pdf->fill(0.94, 0.96, 0.98);
        $pdf->rect(46, $bottom + 14, 100, 122);
        $imagePath = rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string) ($order['image'] ?? ''), '/\\');
        $pdf->imageJpeg($imagePath, 48, $bottom + 16, 96, 118);

        $pdf->text(161, $top - 22, 12, delivery_pdf_crop((string) $order['customer'], 38), [0.05, 0.12, 0.22]);
        $pdf->text(161, $top - 40, 8.5, 'RÉF. ' . delivery_pdf_crop((string) $order['order_ref'], 30), [0.35, 0.43, 0.53]);
        $pdf->text(435, $top - 22, 10, (string) $order['amount'], [0.06, 0.36, 0.22]);
        $pdf->text(435, $top - 40, 8, 'À encaisser', [0.35, 0.43, 0.53]);
        $pdf->stroke(0.88, 0.90, 0.93, 0.6);
        $pdf->line(161, $top - 51, 548, $top - 51);
        $details = [
            'Téléphone : ' . (string) $order['phone'],
            'Quartier : ' . (string) $order['district'],
            'Montre : ' . (string) $order['product'] . ' · ' . (string) $order['variant'],
            'Quantité : ' . (string) $order['quantity'],
        ];
        $y = $top - 69;
        foreach ($details as $detail) {
            foreach (delivery_pdf_lines($detail, 58) as $line) {
                $pdf->text(161, $y, 9, $line, [0.17, 0.25, 0.35]);
                $y -= 13;
            }
        }

        if (($index + 1) % $perPage === 0 || $index + 1 === $total) {
            $pdf->stroke(0.84, 0.88, 0.92, 0.6);
            $pdf->line(38, 28, 557, 28);
            $pdf->text(38, 15, 8, 'L’Horloger · Bordereau de livraison', [0.39, 0.46, 0.55]);
            $pdf->text(478, 15, 8, 'Page ' . (int) floor($index / $perPage + 1), [0.39, 0.46, 0.55]);
        }
    }
    return $pdf->output();
}
