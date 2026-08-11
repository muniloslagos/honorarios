<?php
declare(strict_types=1);

function convenioPdfEscape(string $text): string
{
    $text = str_replace(["\r", "\n"], ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    } else {
        $text = strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'ñ' => 'n', 'Ñ' => 'N', 'ü' => 'u', 'Ü' => 'U', '°' => 'o',
        ]);
    }
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function convenioPdfTextWidth(string $text, float $fontSize): float
{
    $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $units = 0.0;
    foreach ($characters as $character) {
        if ($character === ' ') {
            $units += 0.28;
        } elseif (preg_match('/[MWÁÉÍÓÚÜÑ]/u', $character) === 1) {
            $units += 0.82;
        } elseif (preg_match('/[A-Z0-9]/u', $character) === 1) {
            $units += 0.62;
        } elseif (preg_match('/[ilI.,:;!|]/u', $character) === 1) {
            $units += 0.27;
        } elseif (preg_match('/[mw]/u', $character) === 1) {
            $units += 0.76;
        } else {
            $units += 0.51;
        }
    }
    return $units * $fontSize;
}

function convenioPdfBreakWord(string $word, float $maxWidth, float $fontSize): array
{
    $parts = [];
    $current = '';
    foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
        $candidate = $current . $character;
        if ($current !== '' && convenioPdfTextWidth($candidate, $fontSize) > $maxWidth) {
            $parts[] = $current;
            $current = $character;
        } else {
            $current = $candidate;
        }
    }
    if ($current !== '') {
        $parts[] = $current;
    }
    return $parts ?: [''];
}

function convenioPdfWrapText(string $text, float $maxWidth, float $fontSize): array
{
    $paragraphs = preg_split('/\R/u', trim($text));
    if ($paragraphs === false || $paragraphs === []) {
        return [''];
    }

    $lines = [];
    foreach ($paragraphs as $paragraph) {
        $words = preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            $lines[] = '';
            continue;
        }

        $current = '';
        foreach ($words as $word) {
            $wordParts = convenioPdfTextWidth($word, $fontSize) > $maxWidth
                ? convenioPdfBreakWord($word, $maxWidth, $fontSize)
                : [$word];
            foreach ($wordParts as $wordPart) {
                $candidate = $current === '' ? $wordPart : $current . ' ' . $wordPart;
                if ($current !== '' && convenioPdfTextWidth($candidate, $fontSize) > $maxWidth) {
                    $lines[] = $current;
                    $current = $wordPart;
                } else {
                    $current = $candidate;
                }
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
    }
    return $lines ?: [''];
}

function convenioPdfText(string $text, float $x, float $y, float $size = 9.0, string $font = 'F1'): string
{
    return sprintf(
        "BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
        $font,
        $size,
        $x,
        $y,
        convenioPdfEscape($text)
    );
}

function convenioPdfLine(float $x1, float $y1, float $x2, float $y2, float $width = 0.45): string
{
    return sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $width, $x1, $y1, $x2, $y2);
}

function convenioPdfRect(float $x, float $y, float $width, float $height, float $lineWidth = 0.45): string
{
    return sprintf("%.2F w %.2F %.2F %.2F %.2F re S\n", $lineWidth, $x, $y, $width, $height);
}

function convenioPdfCenteredText(string $text, float $centerX, float $y, float $size, string $font = 'F1'): string
{
    $x = $centerX - (convenioPdfTextWidth($text, $size) / 2);
    return convenioPdfText($text, max(24.0, $x), $y, $size, $font);
}

function convenioPdfDate(?string $date): string
{
    $date = trim((string) $date);
    if ($date === '') {
        return '';
    }
    $timestamp = strtotime($date);
    return $timestamp === false ? $date : date('d-m-Y', $timestamp);
}

function convenioPdfMonthName(int $month): string
{
    $months = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    return $months[$month] ?? (string) $month;
}

function convenioPdfDrawTitleAndInformation(array $report): array
{
    $pageWidth = 595.0;
    $left = 25.0;
    $tableWidth = 545.0;
    $labelWidth = 258.0;
    $title = 'INFORME DE LAS FUNCIONES DESARROLLADAS SEGÚN CONVENIO PRESTACIÓN DE SERVICIOS';
    $stream = convenioPdfCenteredText($title, $pageWidth / 2, 811.0, 9.5, 'F1');

    $rows = [
        ['Mes', convenioPdfMonthName((int) ($report['report_month'] ?? 0))],
        ['Año', (string) ($report['report_year'] ?? '')],
        ['Nombre del prestador de servicios', (string) ($report['provider_name'] ?? '')],
        ['Profesión, oficio y/o experiencia', (string) ($report['profession_experience'] ?? '')],
        ['Programa, Convenio y/o actividad', (string) ($report['program_activity_text'] ?? '')],
        ['Dirección o Unidad que supervisa', (string) ($report['supervision_unit'] ?? '')],
        ['Decreto Afecto/Exento Aprueba Convenio', (string) ($report['decree_number_text'] ?? '')],
        [
            'Número y fecha de boleta',
            trim((string) ($report['boleta_number'] ?? '') . '  ' . convenioPdfDate($report['boleta_date'] ?? null)),
        ],
        [
            'Vigencia del convenio',
            convenioPdfDate($report['agreement_start_date'] ?? null) . ' al ' . convenioPdfDate($report['agreement_end_date'] ?? null),
        ],
        ['N° de cuotas si corresponde', (string) ($report['installment_number'] ?? '')],
    ];

    $top = 794.0;
    $y = $top;
    $fontSize = 8.7;
    $lineHeight = 10.5;
    $padding = 4.0;
    foreach ($rows as [$label, $value]) {
        $labelLines = convenioPdfWrapText($label, $labelWidth - ($padding * 2), $fontSize);
        $valueLines = convenioPdfWrapText($value, $tableWidth - $labelWidth - ($padding * 2), $fontSize);
        $lineCount = max(count($labelLines), count($valueLines), 1);
        $rowHeight = max(18.0, ($lineCount * $lineHeight) + ($padding * 2));
        $bottom = $y - $rowHeight;

        $stream .= convenioPdfRect($left, $bottom, $labelWidth, $rowHeight);
        $stream .= convenioPdfRect($left + $labelWidth, $bottom, $tableWidth - $labelWidth, $rowHeight);
        foreach ($labelLines as $index => $line) {
            $stream .= convenioPdfText($line, $left + $padding, $y - $padding - $fontSize - ($index * $lineHeight), $fontSize);
        }
        foreach ($valueLines as $index => $line) {
            $stream .= convenioPdfText($line, $left + $labelWidth + $padding, $y - $padding - $fontSize - ($index * $lineHeight), $fontSize);
        }
        $y = $bottom;
    }

    return [$stream, $y - 24.0];
}

function convenioPdfDrawActivitiesHeader(float $top): array
{
    $left = 25.0;
    $tableWidth = 545.0;
    $functionWidth = 152.0;
    $headerHeight = 68.0;
    $bottom = $top - $headerHeight;
    $stream = convenioPdfRect($left, $bottom, $functionWidth, $headerHeight);
    $stream .= convenioPdfRect($left + $functionWidth, $bottom, $tableWidth - $functionWidth, $headerHeight);

    $stream .= convenioPdfText('Funciones', $left + 7.0, $top - 14.0, 9.0, 'F2');
    $stream .= convenioPdfText('(según convenio)', $left + 7.0, $top - 28.0, 8.7, 'F2');
    $stream .= convenioPdfLine($left + 7.0, $top - 30.0, $left + 73.0, $top - 30.0, 0.5);

    $rightX = $left + $functionWidth + 7.0;
    $stream .= convenioPdfText('Actividades Desarrolladas', $rightX, $top - 14.0, 9.0, 'F2');
    $instruction = 'Indique en esta columna todas las actividades desarrolladas para el cumplimiento del objetivo, indicando cantidad o números cuando corresponda y si acompaña medios de verificación.';
    $instructionLines = convenioPdfWrapText($instruction, $tableWidth - $functionWidth - 14.0, 8.2);
    foreach (array_slice($instructionLines, 0, 4) as $index => $line) {
        $stream .= convenioPdfText($line, $rightX, $top - 29.0 - ($index * 10.0), 8.2, 'F3');
    }

    return [$stream, $bottom];
}

function convenioPdfNewContinuationPage(): array
{
    $title = 'INFORME DE LAS FUNCIONES DESARROLLADAS SEGÚN CONVENIO PRESTACIÓN DE SERVICIOS';
    $stream = convenioPdfCenteredText($title, 595.0 / 2, 811.0, 9.5, 'F1');
    [$header, $bottom] = convenioPdfDrawActivitiesHeader(791.0);
    return [$stream . $header, $bottom];
}

function convenioPdfBuildDocument(array $pageStreams): string
{
    $objects = [];
    $pageCount = count($pageStreams);
    $pageObjectIds = [];
    for ($index = 0; $index < $pageCount; $index++) {
        $pageObjectIds[] = 6 + ($index * 2);
    }

    $objects[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $objects[2] = "2 0 obj\n<< /Type /Pages /Kids [" . implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageObjectIds)) . "] /Count {$pageCount} >>\nendobj\n";
    $objects[3] = "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";
    $objects[4] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";
    $objects[5] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>\nendobj\n";

    foreach ($pageStreams as $index => $stream) {
        $pageNumber = $index + 1;
        $stream .= convenioPdfCenteredText('Página ' . $pageNumber . ' de ' . $pageCount, 595.0 / 2, 22.0, 7.0, 'F1');
        $pageId = 6 + ($index * 2);
        $contentId = $pageId + 1;
        $objects[$pageId] = "{$pageId} 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents {$contentId} 0 R >>\nendobj\n";
        $objects[$contentId] = "{$contentId} 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream\nendobj\n";
    }

    ksort($objects);
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $object;
    }

    $xrefOffset = strlen($pdf);
    $maxObjectId = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxObjectId + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($id = 1; $id <= $maxObjectId; $id++) {
        $pdf .= str_pad((string) ($offsets[$id] ?? 0), 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . ($maxObjectId + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF";
    return $pdf;
}

function buildConvenioReportPdf(array $report, array $activities): string
{
    [$firstStream, $activitiesTop] = convenioPdfDrawTitleAndInformation($report);
    [$activitiesHeader, $currentY] = convenioPdfDrawActivitiesHeader($activitiesTop);
    $pageStreams = [$firstStream . $activitiesHeader];
    $pageIndex = 0;

    $left = 25.0;
    $tableWidth = 545.0;
    $functionWidth = 152.0;
    $padding = 7.0;
    $fontSize = 8.8;
    $lineHeight = 11.2;
    $minimumRowHeight = 70.0;
    $bottomLimit = 205.0;

    foreach ($activities as $activity) {
        $functionText = trim((string) ($activity['function_title'] ?? ''));
        if ($functionText !== '' && !str_starts_with($functionText, '-')) {
            $functionText = '- ' . $functionText;
        }
        $activityText = trim((string) ($activity['activity_description'] ?? ''));
        $functionLines = convenioPdfWrapText($functionText, $functionWidth - ($padding * 2), $fontSize);
        $activityLines = convenioPdfWrapText($activityText, $tableWidth - $functionWidth - ($padding * 2), $fontSize);

        while ($functionLines !== [] || $activityLines !== []) {
            $remainingLineCount = max(count($functionLines), count($activityLines), 1);
            $requiredHeight = max($minimumRowHeight, ($remainingLineCount * $lineHeight) + ($padding * 2));
            $availableHeight = $currentY - $bottomLimit;

            if ($availableHeight < min($minimumRowHeight, $requiredHeight)) {
                [$newStream, $currentY] = convenioPdfNewContinuationPage();
                $pageStreams[] = $newStream;
                $pageIndex++;
                $availableHeight = $currentY - $bottomLimit;
            }

            $maxLines = max(1, (int) floor(($availableHeight - ($padding * 2)) / $lineHeight));
            $chunkSize = min($remainingLineCount, $maxLines);
            $functionChunk = array_splice($functionLines, 0, $chunkSize);
            $activityChunk = array_splice($activityLines, 0, $chunkSize);
            $hasMore = $functionLines !== [] || $activityLines !== [];
            $rowHeight = $hasMore
                ? $availableHeight
                : max($minimumRowHeight, ($chunkSize * $lineHeight) + ($padding * 2));
            $bottom = $currentY - $rowHeight;

            $pageStreams[$pageIndex] .= convenioPdfRect($left, $bottom, $functionWidth, $rowHeight);
            $pageStreams[$pageIndex] .= convenioPdfRect($left + $functionWidth, $bottom, $tableWidth - $functionWidth, $rowHeight);
            foreach ($functionChunk as $index => $line) {
                $pageStreams[$pageIndex] .= convenioPdfText($line, $left + $padding, $currentY - $padding - $fontSize - ($index * $lineHeight), $fontSize, 'F2');
            }
            foreach ($activityChunk as $index => $line) {
                $pageStreams[$pageIndex] .= convenioPdfText($line, $left + $functionWidth + $padding, $currentY - $padding - $fontSize - ($index * $lineHeight), $fontSize);
            }
            $currentY = $bottom;

            if ($hasMore) {
                [$newStream, $currentY] = convenioPdfNewContinuationPage();
                $pageStreams[] = $newStream;
                $pageIndex++;
            }
        }
    }

    return convenioPdfBuildDocument($pageStreams);
}
