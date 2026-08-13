<?php
declare(strict_types=1);

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/honorario_data.php';
require_once __DIR__ . '/src/mailer.php';
require_once __DIR__ . '/src/convenio_report_pdf.php';

$authUser = requireRole(ROLE_HONORARIO);
$dbUser = ensureHonorarioDbUser($authUser);
$pdo = db();

$configuredDirectionName = '';
$configuredDirectionId = (int) ($dbUser['direction_id'] ?? 0);
if ($configuredDirectionId > 0) {
    $directionStmt = $pdo->prepare('SELECT name FROM directions WHERE id = :id AND is_active = 1 LIMIT 1');
    $directionStmt->execute(['id' => $configuredDirectionId]);
    $configuredDirectionName = trim((string) ($directionStmt->fetchColumn() ?: ''));
}

$success = '';
$error = '';
$showCreateForm = false;
$selectedCreateSource = 'CONVENIO';
$selectedReportId = (int) ($_GET['report_id'] ?? 0);
$wizardStep = max(1, min(4, (int) ($_GET['step'] ?? 2)));
if ((string) ($_GET['notice'] ?? '') === 'activities_saved') {
    $success = 'Actividades y antecedentes de la boleta guardados correctamente.';
} elseif ((string) ($_GET['notice'] ?? '') === 'draft_saved') {
    $success = 'Borrador guardado correctamente. Puedes continuar ahora o regresar más tarde.';
} elseif ((string) ($_GET['notice'] ?? '') === 'manual_boleta_saved') {
    $success = 'Antecedentes de la boleta guardados correctamente.';
}

function pdfEscape(string $text): string
{
    $text = str_replace("\r", ' ', $text);
    $text = str_replace("\n", ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    $text = trim($text);
    if (function_exists('iconv')) {
        $text = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text) ?: $text;
    }
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace('(', '\\(', $text);
    $text = str_replace(')', '\\)', $text);
    return $text;
}

function buildSimplePdf(array $lines): string
{
    $content = "BT\n/F1 11 Tf\n50 800 Td\n14 TL\n";
    foreach ($lines as $line) {
        $content .= '(' . pdfEscape($line) . ") Tj\nT*\n";
    }
    $content .= "ET\n";

    $objects = [];
    $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>\nendobj\n";
    $objects[] = "4 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n";
    $objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= $obj;
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

    return $pdf;
}

function prepareConvenioReportPdf(PDO $pdo, int $reportId, int $honorarioUserId): array
{
    $reportStmt = $pdo->prepare("SELECT * FROM monthly_reports
                                 WHERE id = :id
                                   AND honorario_user_id = :uid
                                   AND source_type = 'CONVENIO'
                                   AND status IN ('BORRADOR', 'RECHAZADO')
                                 LIMIT 1");
    $reportStmt->execute(['id' => $reportId, 'uid' => $honorarioUserId]);
    $report = $reportStmt->fetch();
    if ($report === false) {
        throw new RuntimeException('El informe no está disponible para preparar y firmar.');
    }

    $activityStmt = $pdo->prepare('SELECT COALESCE(NULLIF(a.function_title, \'\'), af.function_text) AS function_title,
                                          a.activity_description
                                   FROM monthly_report_activities a
                                   INNER JOIN monthly_reports r ON r.id = a.report_id
                                   LEFT JOIN agreement_functions af
                                     ON af.agreement_id = r.agreement_id
                                    AND af.sort_order = a.sort_order
                                   WHERE a.report_id = :id
                                   ORDER BY a.sort_order, a.id');
    $activityStmt->execute(['id' => $reportId]);
    $activities = $activityStmt->fetchAll();
    if (!$activities) {
        throw new RuntimeException('Guarda las actividades antes de firmar el informe.');
    }

    $pdf = buildConvenioReportPdf($report, $activities);
    $dir = __DIR__ . '/uploads/reports/generated';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No fue posible crear la carpeta de informes.');
    }
    $name = 'informe_' . $reportId . '_' . date('YmdHis') . '.pdf';
    $fullPath = $dir . '/' . $name;
    if (file_put_contents($fullPath, $pdf, LOCK_EX) === false) {
        throw new RuntimeException('No fue posible preparar el PDF.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM monthly_report_files WHERE report_id = :id AND file_type = 'RESPALDO'")->execute(['id' => $reportId]);
        $insertFile = $pdo->prepare("INSERT INTO monthly_report_files (report_id, file_type, original_name, stored_path, mime_type, size_bytes)
                                     VALUES (:id, 'RESPALDO', :name, :path, 'application/pdf', :size)");
        $insertFile->execute([
            'id' => $reportId,
            'name' => $name,
            'path' => 'uploads/reports/generated/' . $name,
            'size' => strlen($pdf),
        ]);
        $fileId = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE monthly_reports
                       SET status = 'BORRADOR', director_rejection_observation = NULL, observations = NULL
                       WHERE id = :id")->execute(['id' => $reportId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (is_file($fullPath)) unlink($fullPath);
        throw $e;
    }

    return ['file_id' => $fileId, 'name' => $name];
}
if ((string) ($_GET['action'] ?? '') === 'preview_pdf' && $selectedReportId > 0) {
    $reportPreviewStmt = $pdo->prepare('SELECT report_month, report_year, provider_name, profession_experience, supervision_unit, source_type, program_activity_text, decree_number_text, agreement_start_date, agreement_end_date, installment_number, boleta_number, boleta_date, boleta_amount
                                        FROM monthly_reports
                                        WHERE id = :id AND honorario_user_id = :uid
                                        LIMIT 1');
    $reportPreviewStmt->execute(['id' => $selectedReportId, 'uid' => $dbUser['id']]);
    $previewReport = $reportPreviewStmt->fetch();

    if ($previewReport === false) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Informe no encontrado.';
        exit;
    }

    $activityPreviewStmt = $pdo->prepare('SELECT COALESCE(NULLIF(a.function_title, \'\'), af.function_text) AS function_title,
                                                a.activity_description
                                         FROM monthly_report_activities a
                                         INNER JOIN monthly_reports r ON r.id = a.report_id
                                         LEFT JOIN agreement_functions af
                                           ON af.agreement_id = r.agreement_id
                                          AND af.sort_order = a.sort_order
                                         WHERE a.report_id = :id
                                         ORDER BY a.sort_order ASC, a.id ASC');
    $activityPreviewStmt->execute(['id' => $selectedReportId]);
    $activityRows = $activityPreviewStmt->fetchAll();

    $previewMonthNames = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    $monthValue = (int) ($previewReport['report_month'] ?? 0);
    $periodLabel = ($previewMonthNames[$monthValue] ?? (string) $monthValue) . ' ' . (string) ($previewReport['report_year'] ?? '');

    $lines = [
        'Vista previa de informe mensual',
        'Periodo: ' . $periodLabel,
        'Prestador: ' . (string) ($previewReport['provider_name'] ?? ''),
        'Profesion/Oficio: ' . (string) ($previewReport['profession_experience'] ?? ''),
        'Direccion/Unidad: ' . (string) ($previewReport['supervision_unit'] ?? ''),
        'Origen: ' . ((string) ($previewReport['source_type'] ?? '') === 'CONVENIO' ? 'Convenio' : 'Manual'),
        'Programa/Actividad: ' . (string) ($previewReport['program_activity_text'] ?? ''),
        'Decreto: ' . (string) ($previewReport['decree_number_text'] ?? ''),
        'Vigencia: ' . (string) ($previewReport['agreement_start_date'] ?? '') . ' a ' . (string) ($previewReport['agreement_end_date'] ?? ''),
        'Cuota: ' . (string) ($previewReport['installment_number'] ?? ''),
        'Boleta: ' . (string) ($previewReport['boleta_number'] ?? '') . ' (' . (string) ($previewReport['boleta_date'] ?? '') . ')',
        ' ',
        'Actividades:'
    ];

    if (count($activityRows) === 0) {
        $lines[] = '- Sin actividades registradas.';
    } else {
        $idx = 1;
        foreach ($activityRows as $row) {
            $functionTitle = trim((string) ($row['function_title'] ?? ''));
            $activityText = (string) ($row['activity_description'] ?? '');
            if ($functionTitle !== '') {
                $lines[] = $idx . '. Funcion: ' . $functionTitle;
                $lines[] = '   Actividad: ' . $activityText;
            } else {
                $lines[] = $idx . '. ' . $activityText;
            }
            $idx++;
        }
    }

    $pdfContent = (string) ($previewReport['source_type'] ?? '') === 'CONVENIO'
        ? buildConvenioReportPdf($previewReport, $activityRows)
        : buildSimplePdf($lines);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="vista_previa_informe_' . $selectedReportId . '.pdf"');
    header('Content-Length: ' . strlen($pdfContent));
    echo $pdfContent;
    exit;
}

if ((string) ($_GET['action'] ?? '') === 'view_uploaded_pdf') {
    $fileId = (int) ($_GET['file_id'] ?? 0);
    $fileStmt = $pdo->prepare('SELECT f.original_name, f.stored_path
                               FROM monthly_report_files f
                               INNER JOIN monthly_reports r ON r.id = f.report_id
                               WHERE f.id = :id
                                 AND f.file_type = \'RESPALDO\'
                                 AND r.honorario_user_id = :uid
                               LIMIT 1');
    $fileStmt->execute(['id' => $fileId, 'uid' => $dbUser['id']]);
    $uploadedPdf = $fileStmt->fetch();

    $storedPath = $uploadedPdf !== false ? (string) $uploadedPdf['stored_path'] : '';
    $absolutePath = $storedPath !== ''
        ? __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storedPath)
        : '';
    $uploadsRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
    $resolvedPath = $absolutePath !== '' ? realpath($absolutePath) : false;

    if (
        $uploadedPdf === false
        || $uploadsRoot === false
        || $resolvedPath === false
        || !str_starts_with($resolvedPath, $uploadsRoot . DIRECTORY_SEPARATOR)
        || !is_file($resolvedPath)
    ) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'PDF no encontrado.';
        exit;
    }

    $downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $uploadedPdf['original_name']) ?: 'informe.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string) filesize($resolvedPath));
    header('X-Content-Type-Options: nosniff');
    readfile($resolvedPath);
    exit;
}
if ((string) ($_GET['action'] ?? '') === 'view_document_pdf' && $selectedReportId > 0) {
    $documentType = strtoupper((string) ($_GET['type'] ?? ''));
    if (!in_array($documentType, ['REPORT', 'CERTIFICATE', 'BOLETA', 'DECREE', 'AGREEMENT'], true)) {
        http_response_code(400); exit('Tipo de documento no válido.');
    }
    $documentStmt = $pdo->prepare("SELECT r.id,r.status,r.agreement_id,a.pdf_path AS agreement_path,d.pdf_path AS decree_path,
        (SELECT f.stored_path FROM monthly_report_files f WHERE f.report_id=r.id AND f.file_type='RESPALDO' ORDER BY f.id DESC LIMIT 1) AS report_path,
        (SELECT f.stored_path FROM monthly_report_files f WHERE f.report_id=r.id AND f.file_type='CERTIFICADO' ORDER BY f.id DESC LIMIT 1) AS certificate_path,
        (SELECT f.stored_path FROM monthly_report_files f WHERE f.report_id=r.id AND f.file_type='BOLETA' ORDER BY f.id DESC LIMIT 1) AS boleta_path,
        (SELECT f.stored_path FROM monthly_report_files f WHERE f.report_id=r.id AND f.file_type='CONVENIO_FIRMADO' ORDER BY f.id DESC LIMIT 1) AS manual_agreement_path,
        (SELECT f.stored_path FROM monthly_report_files f WHERE f.report_id=r.id AND f.file_type='DECRETO' ORDER BY f.id DESC LIMIT 1) AS manual_decree_path
        FROM monthly_reports r LEFT JOIN agreements a ON a.id=r.agreement_id LEFT JOIN decrees d ON d.id=a.decree_id
        WHERE r.id=:id AND r.honorario_user_id=:uid AND r.status IN ('APROBADO','APROBADO_PAGO') LIMIT 1");
    $documentStmt->execute(['id'=>$selectedReportId,'uid'=>$dbUser['id']]);
    $document = $documentStmt->fetch();
    $pathKey = ['REPORT'=>'report_path','CERTIFICATE'=>'certificate_path','BOLETA'=>'boleta_path'][$documentType] ?? '';
    $storedPath = '';
    if ($document !== false) {
        if ($documentType === 'AGREEMENT') $storedPath = (string) ($document['agreement_path'] ?: $document['manual_agreement_path']);
        elseif ($documentType === 'DECREE') $storedPath = (string) ($document['decree_path'] ?: $document['manual_decree_path']);
        else $storedPath = (string) ($document[$pathKey] ?? '');
    }
    $uploadsRoot = realpath(__DIR__ . '/uploads');
    $resolved = $storedPath !== '' ? realpath(__DIR__ . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storedPath)) : false;
    if ($document === false || $uploadsRoot === false || $resolved === false || !str_starts_with($resolved, $uploadsRoot . DIRECTORY_SEPARATOR) || !is_file($resolved)) {
        http_response_code(404); header('Content-Type: text/plain; charset=UTF-8'); echo 'Documento no disponible.'; exit;
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . strtolower($documentType) . '_' . $selectedReportId . '.pdf"');
    header('Content-Length: ' . filesize($resolved));
    header('X-Content-Type-Options: nosniff');
    readfile($resolved); exit;
}
$agreementsStmt = $pdo->prepare("SELECT a.id, a.agreement_number, a.start_date, a.end_date, a.program_item, a.installments_total,
                                        a.profession_experience, a.supervision_unit, d.decree_number,
                                        (SELECT COALESCE(MAX(r.installment_number), 0) + 1
                                         FROM monthly_reports r
                                         WHERE r.agreement_id = a.id
                                           AND r.honorario_user_id = a.honorario_user_id
                                           AND r.status = 'APROBADO'
                                           AND r.installment_number IS NOT NULL) AS next_installment
                                 FROM agreements a
                                 LEFT JOIN decrees d ON d.id = a.decree_id
                                 WHERE a.honorario_user_id = :uid
                                 ORDER BY a.start_date DESC");
$agreementsStmt->execute(['uid' => $dbUser['id']]);
$agreements = $agreementsStmt->fetchAll();
$today = date('Y-m-d');
$activeAgreements = array_values(array_filter($agreements, static function (array $agreement) use ($today): bool {
    $startDate = (string) ($agreement['start_date'] ?? '');
    $endDate = (string) ($agreement['end_date'] ?? '');

    return $startDate !== '' && $endDate !== '' && $startDate <= $today && $endDate >= $today;
}));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? 'create_report');

        if ($action === 'create_report') {
            $month = (int) ($_POST['report_month'] ?? 0);
            $year = (int) ($_POST['report_year'] ?? 0);
            $provider = trim((string) ($_POST['provider_name'] ?? ''));
            $profession = trim((string) ($_POST['profession_experience'] ?? ''));
            $supervisionUnit = $configuredDirectionName;
            $saveProfileRecords = isset($_POST['save_profile_records']);

            $sourceType = (string) ($_POST['source_type'] ?? 'CONVENIO');
            if ($sourceType !== 'MANUAL' && $sourceType !== 'CONVENIO') {
                $sourceType = 'CONVENIO';
            }
            $selectedCreateSource = $sourceType;

            $agreementIdRaw = trim((string) ($_POST['agreement_id'] ?? ''));
            $agreementId = $agreementIdRaw !== '' ? (int) $agreementIdRaw : null;

            $programText = trim((string) ($_POST['program_activity_text'] ?? ''));
            $decreeText = trim((string) ($_POST['decree_number_text'] ?? ''));
            $decreeDate = trim((string) ($_POST['decree_date_manual'] ?? ''));
            $startDate = trim((string) ($_POST['agreement_start_date'] ?? ''));
            $endDate = trim((string) ($_POST['agreement_end_date'] ?? ''));
            $installmentRaw = trim((string) ($_POST['installment_number'] ?? ''));
            $installment = $installmentRaw !== '' ? (int) $installmentRaw : null;
            $installmentsTotalRaw = trim((string) ($_POST['installments_total_manual'] ?? ''));
            $installmentsTotalManual = $installmentsTotalRaw !== '' ? (int) $installmentsTotalRaw : null;
            $agreementNumberManual = trim((string) ($_POST['agreement_number_manual'] ?? ''));
            $agreementDateManual = trim((string) ($_POST['agreement_date_manual'] ?? ''));
            $boletaNumber = trim((string) ($_POST['boleta_number'] ?? ''));
            $boletaDate = trim((string) ($_POST['boleta_date'] ?? ''));
            $boletaAmountRaw = str_replace(['.', ','], ['', '.'], trim((string) ($_POST['boleta_amount'] ?? '')));
            $boletaAmount = $boletaAmountRaw !== '' && is_numeric($boletaAmountRaw) ? (float) $boletaAmountRaw : null;

            if ($month < 1 || $month > 12 || $year < 2020) {
                throw new RuntimeException('Mes o anio invalido.');
            }
            if ($provider === '' || $profession === '') {
                throw new RuntimeException('Nombre prestador y profesion/oficio son obligatorios.');
            }
            if ($supervisionUnit === '') {
                throw new RuntimeException('El administrador debe asignarte una dirección antes de crear el informe.');
            }
            if ($sourceType === 'MANUAL' && $saveProfileRecords) {
                if ($agreementNumberManual === '' || $agreementDateManual === '' || $decreeText === '' || $decreeDate === '') {
                    throw new RuntimeException('Para guardar convenio y decreto en el perfil debes completar numero y fecha de convenio, y numero y fecha de decreto.');
                }
                if ($installmentsTotalManual === null || $installmentsTotalManual < 1) {
                    throw new RuntimeException('Para guardar el convenio en el perfil debes informar el total de cuotas.');
                }
            }
            if ($programText === '') {
                throw new RuntimeException('Debes informar programa, convenio y/o actividad.');
            }
            if ($startDate === '' || $endDate === '') {
                throw new RuntimeException('Debes ingresar vigencia inicio y fin.');
            }

            $agreementRow = null;

            if ($sourceType === 'CONVENIO') {
                if ($agreementId === null) {
                    throw new RuntimeException('Debes seleccionar convenio para este tipo de informe.');
                }

                $agreementFetch = $pdo->prepare('SELECT a.id, a.agreement_number, a.start_date, a.end_date, a.installments_total, a.program_item, a.profession_experience, a.supervision_unit, d.decree_number, d.decree_date
                                                 FROM agreements a
                                                 LEFT JOIN decrees d ON d.id = a.decree_id
                                                 WHERE a.id = :id AND a.honorario_user_id = :uid LIMIT 1');
                $agreementFetch->execute(['id' => $agreementId, 'uid' => $dbUser['id']]);
                $agreementRow = $agreementFetch->fetch();

                if ($agreementRow === false) {
                    throw new RuntimeException('No se encontro el convenio seleccionado.');
                }
                $agreementStartDate = (string) ($agreementRow['start_date'] ?? '');
                $agreementEndDate = (string) ($agreementRow['end_date'] ?? '');
                $currentDate = date('Y-m-d');
                if ($agreementStartDate === '' || $agreementEndDate === '' || $agreementStartDate > $currentDate || $agreementEndDate < $currentDate) {
                    throw new RuntimeException('El convenio seleccionado no se encuentra vigente.');
                }

                $profession = trim((string) ($agreementRow['profession_experience'] ?? $profession));
                $supervisionUnit = $configuredDirectionName;
                $programText = trim((string) ($agreementRow['program_item'] ?? $programText));
                $decreeText = trim((string) ($agreementRow['decree_number'] ?? $decreeText));
                $decreeDate = trim((string) ($agreementRow['decree_date'] ?? $decreeDate));
                $startDate = trim((string) ($agreementRow['start_date'] ?? $startDate));
                $endDate = trim((string) ($agreementRow['end_date'] ?? $endDate));

                $maxInstallments = isset($agreementRow['installments_total']) ? (int) $agreementRow['installments_total'] : null;
                if ($installment === null || $installment < 1) {
                    throw new RuntimeException('Debes seleccionar el número de cuota del informe.');
                }
                if ($maxInstallments !== null && $maxInstallments > 0) {
                    if ($installment === null || $installment < 1 || $installment > $maxInstallments) {
                        throw new RuntimeException('Debes seleccionar una cuota valida para el convenio.');
                    }
                }

                $check = $pdo->prepare('SELECT id FROM monthly_reports WHERE honorario_user_id = :uid AND report_month = :m AND report_year = :y AND source_type = :st AND agreement_id = :aid LIMIT 1');
                $check->execute([
                    'uid' => $dbUser['id'],
                    'm' => $month,
                    'y' => $year,
                    'st' => 'CONVENIO',
                    'aid' => $agreementId,
                ]);
                if ($check->fetch() !== false) {
                    throw new RuntimeException('Ya existe un informe para ese convenio en el mes/anio indicado.');
                }
            }

            $insert = $pdo->prepare('INSERT INTO monthly_reports (
                honorario_user_id,
                report_month,
                report_year,
                provider_name,
                profession_experience,
                supervision_unit,
                direction_id,
                source_type,
                agreement_id,
                program_activity_text,
                decree_number_text,
                decree_date,
                agreement_start_date,
                agreement_end_date,
                installment_number,
                boleta_number,
                boleta_date,
                boleta_amount,
                status
            ) VALUES (
                :uid,
                :m,
                :y,
                :provider,
                :profession,
                :supervision_unit,
                :direction_id,
                :source_type,
                :agreement_id,
                :program_text,
                :decree_text,
                :decree_date,
                :start_date,
                :end_date,
                :installment,
                :boleta_number,
                :boleta_date,
                :boleta_amount,
                :status
            )');

            $pdo->beginTransaction();
            try {
                $insert->execute([
                'uid' => $dbUser['id'],
                'm' => $month,
                'y' => $year,
                'provider' => $provider,
                'profession' => $profession,
                'supervision_unit' => $supervisionUnit !== '' ? $supervisionUnit : null,
                'direction_id' => isset($dbUser['direction_id']) ? (int) $dbUser['direction_id'] : null,
                'source_type' => $sourceType,
                'agreement_id' => $sourceType === 'CONVENIO' ? $agreementId : null,
                'program_text' => $programText,
                'decree_text' => $decreeText !== '' ? $decreeText : null,
                'decree_date' => $decreeDate !== '' ? $decreeDate : null,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'installment' => $installment,
                'boleta_number' => $boletaNumber !== '' ? $boletaNumber : null,
                'boleta_date' => $boletaDate !== '' ? $boletaDate : null,
                'boleta_amount' => $boletaAmount,
                'status' => 'BORRADOR',
                ]);

                $selectedReportId = (int) $pdo->lastInsertId();

                if ($sourceType === 'MANUAL') {
                    $reportPdf = uploadPdf($_FILES['report_pdf_manual'] ?? [], 'reports');
                    if ($reportPdf === null) {
                        throw new RuntimeException('Debes cargar el PDF del informe para la modalidad manual.');
                    }

                    $agreementPdf = uploadPdf($_FILES['agreement_pdf_manual'] ?? [], 'agreements/' . $dbUser['run']);
                    $decreePdf = uploadPdf($_FILES['decree_pdf_manual'] ?? [], 'decrees/' . $dbUser['run']);
                    $boletaPdf = uploadPdf($_FILES['boleta_pdf_manual'] ?? [], 'reports');

                    if ($saveProfileRecords && $agreementPdf === null) {
                        throw new RuntimeException('Debes cargar el PDF del convenio si deseas guardarlo en el perfil.');
                    }
                    if ($saveProfileRecords && $decreePdf === null) {
                        throw new RuntimeException('Debes cargar el PDF del decreto si deseas guardarlo en el perfil.');
                    }

                    $sizeBytes = isset($_FILES['report_pdf_manual']['size']) ? (int) $_FILES['report_pdf_manual']['size'] : null;
                    $mimeType = isset($_FILES['report_pdf_manual']['type']) ? (string) $_FILES['report_pdf_manual']['type'] : null;
                    $insertFile = $pdo->prepare('INSERT INTO monthly_report_files (report_id, file_type, original_name, stored_path, mime_type, size_bytes) VALUES (:rid, :type, :oname, :spath, :mime, :size)');
                    $insertFile->execute([
                        'rid' => $selectedReportId,
                        'type' => 'RESPALDO',
                        'oname' => (string) $reportPdf['original_name'],
                        'spath' => (string) $reportPdf['stored_path'],
                        'mime' => $mimeType,
                        'size' => $sizeBytes,
                    ]);

                    if ($agreementPdf !== null) {
                        $sizeBytes = isset($_FILES['agreement_pdf_manual']['size']) ? (int) $_FILES['agreement_pdf_manual']['size'] : null;
                        $mimeType = isset($_FILES['agreement_pdf_manual']['type']) ? (string) $_FILES['agreement_pdf_manual']['type'] : null;
                        $insertFile->execute([
                            'rid' => $selectedReportId,
                            'type' => 'CONVENIO_FIRMADO',
                            'oname' => (string) $agreementPdf['original_name'],
                            'spath' => (string) $agreementPdf['stored_path'],
                            'mime' => $mimeType,
                            'size' => $sizeBytes,
                        ]);
                    }

                    if ($decreePdf !== null) {
                        $sizeBytes = isset($_FILES['decree_pdf_manual']['size']) ? (int) $_FILES['decree_pdf_manual']['size'] : null;
                        $mimeType = isset($_FILES['decree_pdf_manual']['type']) ? (string) $_FILES['decree_pdf_manual']['type'] : null;
                        $insertFile->execute([
                            'rid' => $selectedReportId,
                            'type' => 'DECRETO',
                            'oname' => (string) $decreePdf['original_name'],
                            'spath' => (string) $decreePdf['stored_path'],
                            'mime' => $mimeType,
                            'size' => $sizeBytes,
                        ]);
                    }

                    if ($boletaPdf !== null) {
                        $sizeBytes = isset($_FILES['boleta_pdf_manual']['size']) ? (int) $_FILES['boleta_pdf_manual']['size'] : null;
                        $mimeType = isset($_FILES['boleta_pdf_manual']['type']) ? (string) $_FILES['boleta_pdf_manual']['type'] : null;
                        $insertFile->execute([
                            'rid' => $selectedReportId,
                            'type' => 'BOLETA',
                            'oname' => (string) $boletaPdf['original_name'],
                            'spath' => (string) $boletaPdf['stored_path'],
                            'mime' => $mimeType,
                            'size' => $sizeBytes,
                        ]);
                    }

                    if ($saveProfileRecords) {
                        $decreeInsert = $pdo->prepare('INSERT INTO decrees (honorario_user_id, decree_number, decree_date, pdf_original_name, pdf_path, created_by_user_id)
                                                       VALUES (:uid,:num,:dt,:pdfn,:pdfp,:actor)
                                                       ON DUPLICATE KEY UPDATE decree_date=VALUES(decree_date), pdf_original_name=VALUES(pdf_original_name), pdf_path=VALUES(pdf_path)');
                        $decreeInsert->execute([
                            'uid' => $dbUser['id'],
                            'num' => $decreeText,
                            'dt' => $decreeDate,
                            'pdfn' => (string) $decreePdf['original_name'],
                            'pdfp' => (string) $decreePdf['stored_path'],
                            'actor' => $dbUser['id'],
                        ]);

                        $decreeFind = $pdo->prepare('SELECT id FROM decrees WHERE honorario_user_id = :uid AND decree_number = :num LIMIT 1');
                        $decreeFind->execute(['uid' => $dbUser['id'], 'num' => $decreeText]);
                        $decreeRow = $decreeFind->fetch();
                        if ($decreeRow === false) {
                            throw new RuntimeException('No fue posible registrar el decreto en el perfil.');
                        }

                        $agreementInsert = $pdo->prepare('INSERT INTO agreements (
                            honorario_user_id, agreement_number, agreement_date, start_date, end_date, installments_total,
                            program_item, profession_experience, supervision_unit, decree_id, pdf_original_name, pdf_path, status, created_by_user_id
                        ) VALUES (
                            :uid, :num, :ad, :sd, :ed, :ins, :prog, :profession, :supervision, :decree, :pdfn, :pdfp, :st, :actor
                        )
                        ON DUPLICATE KEY UPDATE
                            agreement_date=VALUES(agreement_date),
                            start_date=VALUES(start_date),
                            end_date=VALUES(end_date),
                            installments_total=VALUES(installments_total),
                            program_item=VALUES(program_item),
                            profession_experience=VALUES(profession_experience),
                            supervision_unit=VALUES(supervision_unit),
                            decree_id=VALUES(decree_id),
                            pdf_original_name=VALUES(pdf_original_name),
                            pdf_path=VALUES(pdf_path),
                            status=VALUES(status)');
                        $agreementInsert->execute([
                            'uid' => $dbUser['id'],
                            'num' => $agreementNumberManual,
                            'ad' => $agreementDateManual,
                            'sd' => $startDate,
                            'ed' => $endDate,
                            'ins' => $installmentsTotalManual,
                            'prog' => $programText,
                            'profession' => $profession,
                            'supervision' => $supervisionUnit,
                            'decree' => (int) $decreeRow['id'],
                            'pdfn' => (string) $agreementPdf['original_name'],
                            'pdfp' => (string) $agreementPdf['stored_path'],
                            'st' => 'VIGENTE',
                            'actor' => $dbUser['id'],
                        ]);
                    }
                }

                $pdo->commit();
            } catch (Throwable $txe) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txe;
            }

            if ($sourceType === 'CONVENIO') {
                redirectTo('informe_mensual.php?report_id=' . $selectedReportId . '&step=2#reportWizard');
            }
            redirectTo('informe_mensual.php?report_id=' . $selectedReportId . '&step=2#reportWizard');
        } elseif ($action === 'save_activities') {
            $reportId = (int) ($_POST['report_id'] ?? 0);

            $reportStmt = $pdo->prepare('SELECT r.id, r.source_type, r.status, r.agreement_id, r.report_month, r.report_year,
                                                r.provider_name, r.profession_experience, r.supervision_unit, r.program_activity_text,
                                                r.decree_number_text, r.agreement_start_date, r.agreement_end_date, r.installment_number,
                                                r.boleta_number, r.boleta_date, r.director_signed_at,
                                                EXISTS(SELECT 1 FROM monthly_report_files bf WHERE bf.report_id = r.id AND bf.file_type = \'BOLETA\') AS has_boleta_pdf,
                                                EXISTS(SELECT 1 FROM signature_requests sr WHERE sr.report_id = r.id AND sr.status = \'PENDIENTE\') AS has_pending_signature,
                                                EXISTS(SELECT 1 FROM signature_requests sr WHERE sr.report_id = r.id AND sr.status = \'FIRMADO\') AS has_employee_signature
                                         FROM monthly_reports r
                                         WHERE r.id = :id AND r.honorario_user_id = :uid
                                         LIMIT 1');
            $reportStmt->execute(['id' => $reportId, 'uid' => $dbUser['id']]);
            $reportRow = $reportStmt->fetch();

            if ($reportRow === false) {
                throw new RuntimeException('Informe no encontrado.');
            }
            if ((string) ($reportRow['status'] ?? '') === 'APROBADO' || trim((string) ($reportRow['director_signed_at'] ?? '')) !== '') {
                throw new RuntimeException('El proceso de firmas está finalizado y las actividades ya no se pueden modificar.');
            }
            if (
                (string) ($reportRow['status'] ?? '') !== 'RECHAZADO'
                && (
                    (string) ($reportRow['status'] ?? '') === 'ENVIADO'
                    || (int) ($reportRow['has_pending_signature'] ?? 0) === 1
                    || (int) ($reportRow['has_employee_signature'] ?? 0) === 1
                )
            ) {
                throw new RuntimeException('El informe está en proceso de firma y sus actividades no se pueden modificar.');
            }
            $sourceType = (string) $reportRow['source_type'];
            $agreementId = (int) ($reportRow['agreement_id'] ?? 0);
            $saveDraftOnly = $sourceType === 'CONVENIO' && (string) ($_POST['wizard_submit'] ?? 'continue') === 'save';

            $finalProfession = trim((string) ($_POST['profession_experience'] ?? ''));
            $finalSupervision = $configuredDirectionName;
            $finalProgram = trim((string) ($_POST['program_activity_text'] ?? ''));
            $finalDecree = trim((string) ($_POST['decree_number_text'] ?? ''));
            $finalStart = trim((string) ($_POST['agreement_start_date'] ?? ''));
            $finalEnd = trim((string) ($_POST['agreement_end_date'] ?? ''));
            $finalInstallmentRaw = trim((string) ($_POST['installment_number'] ?? ''));
            $finalInstallment = $finalInstallmentRaw !== '' ? (int) $finalInstallmentRaw : null;
            $finalBoletaNumber = trim((string) ($_POST['boleta_number'] ?? ''));
            $finalBoletaDate = trim((string) ($_POST['boleta_date'] ?? ''));
            $finalBoletaAmountRaw = str_replace(['.', ','], ['', '.'], trim((string) ($_POST['boleta_amount'] ?? '')));
            $finalBoletaAmount = $finalBoletaAmountRaw !== '' && is_numeric($finalBoletaAmountRaw) ? (float) $finalBoletaAmountRaw : null;

            if ($finalSupervision === '') {
                throw new RuntimeException('El administrador debe asignarte una dirección antes de guardar las actividades.');
            }

            if ($sourceType === 'CONVENIO' && $agreementId > 0) {
                $agreementStmt = $pdo->prepare('SELECT a.id, a.agreement_number, a.start_date, a.end_date, a.installments_total, a.program_item, a.profession_experience, a.supervision_unit, d.decree_number, d.decree_date
                                                FROM agreements a
                                                LEFT JOIN decrees d ON d.id = a.decree_id
                                                WHERE a.id = :id AND a.honorario_user_id = :uid LIMIT 1');
                $agreementStmt->execute(['id' => $agreementId, 'uid' => $dbUser['id']]);
                $agreementRow = $agreementStmt->fetch();

                if ($agreementRow === false) {
                    throw new RuntimeException('No se encontro el convenio asociado al informe.');
                }

                $finalProfession = trim((string) ($agreementRow['profession_experience'] ?? $finalProfession));
                $finalSupervision = $configuredDirectionName;
                $finalProgram = trim((string) ($agreementRow['program_item'] ?? $finalProgram));
                $finalDecree = trim((string) ($agreementRow['decree_number'] ?? $finalDecree));
                $finalStart = trim((string) ($agreementRow['start_date'] ?? $finalStart));
                $finalEnd = trim((string) ($agreementRow['end_date'] ?? $finalEnd));
                $maxInstallments = isset($agreementRow['installments_total']) ? (int) $agreementRow['installments_total'] : null;
                if ($finalInstallment === null || $finalInstallment < 1) {
                    throw new RuntimeException('Debes indicar una cuota válida para el convenio.');
                }
                if ($maxInstallments !== null && $maxInstallments > 0) {
                    if ($finalInstallment === null || $finalInstallment < 1 || $finalInstallment > $maxInstallments) {
                        throw new RuntimeException('Debes indicar una cuota valida para el convenio.');
                    }
                }
                if (!$saveDraftOnly) {
                    $missingStepTwoData = [];
                    if ($finalBoletaNumber === '') $missingStepTwoData[] = 'número de boleta';
                    if ($finalBoletaDate === '') $missingStepTwoData[] = 'fecha de boleta';
                    if ($finalBoletaAmount === null || $finalBoletaAmount <= 0) $missingStepTwoData[] = 'valor líquido de la boleta';
                    if ($missingStepTwoData !== []) {
                        throw new RuntimeException('Completa los antecedentes de la boleta: ' . implode(', ', $missingStepTwoData) . '.');
                    }
                }
            } elseif ($sourceType === 'MANUAL') {
                if ($finalProfession === '' || $finalProgram === '' || $finalSupervision === '' || $finalDecree === '' || $finalStart === '' || $finalEnd === '') {
                    throw new RuntimeException('Completa los datos manuales del informe antes de guardar las actividades.');
                }
            }

            $boletaPdf = uploadPdf($_FILES['boleta_pdf'] ?? [], 'reports/boletas');
            $newBoletaAbsolutePath = $boletaPdf !== null
                ? __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $boletaPdf['stored_path'])
                : '';
            if ($sourceType === 'CONVENIO' && !$saveDraftOnly && $boletaPdf === null && (int) ($reportRow['has_boleta_pdf'] ?? 0) !== 1) {
                throw new RuntimeException('Debes adjuntar el archivo PDF de la boleta para continuar.');
            }
            $previousBoletaPaths = [];
            $pdo->beginTransaction();
            try {
                $updateReport = $pdo->prepare('UPDATE monthly_reports SET
                profession_experience = :profession,
                supervision_unit = :supervision,
                program_activity_text = :program_text,
                decree_number_text = :decree_text,
                agreement_start_date = :start_date,
                agreement_end_date = :end_date,
                installment_number = :installment,
                boleta_number = :boleta_number,
                boleta_date = :boleta_date,
                boleta_amount = :boleta_amount
                WHERE id = :id AND honorario_user_id = :uid');
            $updateReport->execute([
                'profession' => $sourceType === 'CONVENIO' ? ($finalProfession !== '' ? $finalProfession : null) : $finalProfession,
                'supervision' => $sourceType === 'CONVENIO' ? ($finalSupervision !== '' ? $finalSupervision : null) : $finalSupervision,
                'program_text' => $sourceType === 'CONVENIO' ? ($finalProgram !== '' ? $finalProgram : null) : $finalProgram,
                'decree_text' => $sourceType === 'CONVENIO' ? ($finalDecree !== '' ? $finalDecree : null) : $finalDecree,
                'start_date' => $sourceType === 'CONVENIO' ? ($finalStart !== '' ? $finalStart : null) : $finalStart,
                'end_date' => $sourceType === 'CONVENIO' ? ($finalEnd !== '' ? $finalEnd : null) : $finalEnd,
                'installment' => $finalInstallment,
                'boleta_number' => $finalBoletaNumber !== '' ? $finalBoletaNumber : null,
                'boleta_date' => $finalBoletaDate !== '' ? $finalBoletaDate : null,
                'boleta_amount' => $finalBoletaAmount,
                'id' => $reportId,
                'uid' => $dbUser['id'],
            ]);
                if ($boletaPdf !== null) {
                    $previousBoletaStmt = $pdo->prepare("SELECT stored_path FROM monthly_report_files WHERE report_id = :rid AND file_type = 'BOLETA'");
                    $previousBoletaStmt->execute(['rid' => $reportId]);
                    $previousBoletaPaths = $previousBoletaStmt->fetchAll(PDO::FETCH_COLUMN);

                    $pdo->prepare("DELETE FROM monthly_report_files WHERE report_id = :rid AND file_type = 'BOLETA'")->execute(['rid' => $reportId]);
                    $insertBoletaFile = $pdo->prepare("INSERT INTO monthly_report_files (report_id, file_type, original_name, stored_path, mime_type, size_bytes)
                                                       VALUES (:rid, 'BOLETA', :name, :path, 'application/pdf', :size)");
                    $insertBoletaFile->execute([
                        'rid' => $reportId,
                        'name' => (string) $boletaPdf['original_name'],
                        'path' => (string) $boletaPdf['stored_path'],
                        'size' => isset($_FILES['boleta_pdf']['size']) ? (int) $_FILES['boleta_pdf']['size'] : null,
                    ]);
                }

                $pdo->prepare('DELETE FROM monthly_report_activities WHERE report_id = :rid')->execute(['rid' => $reportId]);

                if ($sourceType === 'CONVENIO' && $agreementId > 0) {
                    $functionStmt = $pdo->prepare('SELECT id, function_text FROM agreement_functions WHERE agreement_id = :aid ORDER BY sort_order ASC, id ASC');
                    $functionStmt->execute(['aid' => $agreementId]);
                    $functions = $functionStmt->fetchAll();

                    if (count($functions) === 0) {
                        throw new RuntimeException('El convenio seleccionado no tiene funciones para cargar actividades.');
                    }

                    $activityTexts = $_POST['activity_texts'] ?? [];
                    if (!is_array($activityTexts)) {
                        throw new RuntimeException('Formato de actividades invalido.');
                    }

                    $insertActivity = $pdo->prepare('INSERT INTO monthly_report_activities (report_id, function_title, activity_description, sort_order) VALUES (:rid, :function_title, :desc, :ord)');
                    $inserted = 0;
                    foreach ($functions as $index => $fn) {
                        $text = isset($activityTexts[$index]) ? trim((string) $activityTexts[$index]) : '';
                        if ($text === '' && !$saveDraftOnly) {
                            throw new RuntimeException('Debes completar una actividad para cada funcion del convenio.');
                        }

                        $insertActivity->execute([
                            'rid' => $reportId,
                            'function_title' => (string) ($fn['function_text'] ?? ''),
                            'desc' => $text,
                            'ord' => $index + 1,
                        ]);
                        $inserted++;
                    }

                    if ($inserted === 0) {
                        throw new RuntimeException('No se ingresaron actividades validas.');
                    }
                } else {
                    $manualFunctionTitles = $_POST['manual_function_titles'] ?? [];
                    $manualActivityTexts = $_POST['manual_activity_texts'] ?? [];
                    if (!is_array($manualFunctionTitles) || !is_array($manualActivityTexts)) {
                        throw new RuntimeException('Formato de funciones/actividades manuales invalido.');
                    }

                    $len = max(count($manualFunctionTitles), count($manualActivityTexts));
                    $insertActivity = $pdo->prepare('INSERT INTO monthly_report_activities (report_id, function_title, activity_description, sort_order) VALUES (:rid, :function_title, :desc, :ord)');
                    $inserted = 0;
                    for ($i = 0; $i < $len; $i++) {
                        $functionTitle = trim((string) ($manualFunctionTitles[$i] ?? ''));
                        $activityText = trim((string) ($manualActivityTexts[$i] ?? ''));

                        if ($functionTitle === '' && $activityText === '') {
                            continue;
                        }
                        if ($functionTitle === '' || $activityText === '') {
                            throw new RuntimeException('Cada fila manual debe incluir funcion y actividad.');
                        }

                        $inserted++;
                        $insertActivity->execute([
                            'rid' => $reportId,
                            'function_title' => $functionTitle,
                            'desc' => $activityText,
                            'ord' => $inserted,
                        ]);
                    }

                    if ($inserted === 0) {
                        throw new RuntimeException('Debes ingresar al menos una funcion con su actividad en el informe manual.');
                    }
                }

                $pdo->commit();
            } catch (Throwable $txe) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($newBoletaAbsolutePath !== '' && is_file($newBoletaAbsolutePath)) {
                    @unlink($newBoletaAbsolutePath);
                }
                throw $txe;
            }

            if ($boletaPdf !== null) {
                $uploadsRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
                foreach ($previousBoletaPaths as $previousBoletaPath) {
                    $previousAbsolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $previousBoletaPath);
                    $resolvedPreviousPath = realpath($previousAbsolutePath);
                    if (
                        $uploadsRoot !== false
                        && $resolvedPreviousPath !== false
                        && str_starts_with($resolvedPreviousPath, $uploadsRoot . DIRECTORY_SEPARATOR)
                        && is_file($resolvedPreviousPath)
                    ) {
                        @unlink($resolvedPreviousPath);
                    }
                }
            }
            $selectedReportId = $reportId;
            if ($saveDraftOnly) {
                redirectTo('informe_mensual.php?report_id=' . $selectedReportId . '&step=2&notice=draft_saved#reportWizard');
            }
            redirectTo('informe_mensual.php?report_id=' . $selectedReportId . '&step=3&notice=activities_saved#reportWizard');
        } elseif ($action === 'generate_convenio_pdf') {
            $reportId = (int) ($_POST['report_id'] ?? 0);
            prepareConvenioReportPdf($pdo, $reportId, (int) $dbUser['id']);
            $selectedReportId = $reportId;
            $success = 'PDF preparado. Ahora puedes revisarlo y enviarlo a firma.';
        } elseif ($action === 'reopen_rejected') {
            $reportId = (int) ($_POST['report_id'] ?? 0);
            $reportStmt = $pdo->prepare("SELECT source_type,finance_rejected_at FROM monthly_reports WHERE id=:id AND honorario_user_id=:uid AND status='RECHAZADO' LIMIT 1");
            $reportStmt->execute(['id'=>$reportId,'uid'=>$dbUser['id']]);
            $rejected = $reportStmt->fetch();
            if ($rejected === false) throw new RuntimeException('El informe no está disponible para corrección.');
            $source = (string) $rejected['source_type'];
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE signature_requests SET status='ANULADO' WHERE report_id=:id AND status='PENDIENTE'")->execute(['id'=>$reportId]);
            if (trim((string) ($rejected['finance_rejected_at'] ?? '')) === '') {
                $activeFileStmt = $pdo->prepare("SELECT * FROM monthly_report_files WHERE report_id=:id AND file_type='RESPALDO' ORDER BY id DESC LIMIT 1 FOR UPDATE");
                $activeFileStmt->execute(['id'=>$reportId]);
                $activeFile = $activeFileStmt->fetch();
                if ($activeFile !== false) {
                    $pdo->prepare("INSERT IGNORE INTO monthly_report_file_history (report_id,source_file_id,stage,original_name,stored_path,mime_type,size_bytes)
                                   VALUES (:report,:file,'FIRMADO_FUNCIONARIO',:name,:path,:mime,:size)")
                        ->execute(['report'=>$reportId,'file'=>$activeFile['id'],'name'=>$activeFile['original_name'],'path'=>$activeFile['stored_path'],'mime'=>$activeFile['mime_type'],'size'=>$activeFile['size_bytes']]);
                    $pdo->prepare("UPDATE monthly_report_files SET file_type='HISTORICO' WHERE id=:id")->execute(['id'=>$activeFile['id']]);
                }
                if ($source === 'MANUAL') {
                    $originalStmt = $pdo->prepare("SELECT original_name,stored_path,mime_type,size_bytes FROM monthly_report_file_history WHERE report_id=:id AND stage='ORIGINAL' ORDER BY id DESC LIMIT 1");
                    $originalStmt->execute(['id'=>$reportId]);
                    $original = $originalStmt->fetch();
                    if ($original !== false && is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $original['stored_path']))) {
                        $pdo->prepare("INSERT INTO monthly_report_files (report_id,file_type,original_name,stored_path,mime_type,size_bytes) VALUES (:report,'RESPALDO',:name,:path,:mime,:size)")
                            ->execute(['report'=>$reportId,'name'=>$original['original_name'],'path'=>$original['stored_path'],'mime'=>$original['mime_type'],'size'=>$original['size_bytes']]);
                    }
                }
            }
            $pdo->prepare("UPDATE monthly_reports SET status='BORRADOR' WHERE id=:id")->execute(['id'=>$reportId]);
            $pdo->commit();
            $success = $source === 'MANUAL' ? 'Informe abierto para corrección. Puedes conservar el PDF original o eliminarlo y adjuntar uno corregido.' : 'Informe abierto para corrección. Corrige las actividades y prepara nuevamente el PDF.';
        } elseif ($action === 'save_manual_boleta') {
            $reportId = (int) ($_POST['report_id'] ?? 0);
            $saveManualDraftOnly = (string) ($_POST['wizard_submit'] ?? 'save') === 'save';
            $number = trim((string) ($_POST['boleta_number'] ?? ''));
            $date = trim((string) ($_POST['boleta_date'] ?? ''));
            $amountRaw = str_replace(['.', ','], ['', '.'], trim((string) ($_POST['boleta_amount'] ?? '')));
            $amount = $amountRaw !== '' && is_numeric($amountRaw) ? (float) $amountRaw : null;
            $manualStmt = $pdo->prepare("SELECT id FROM monthly_reports WHERE id=:id AND honorario_user_id=:uid AND source_type='MANUAL' AND status IN ('BORRADOR','RECHAZADO') LIMIT 1");
            $manualStmt->execute(['id'=>$reportId,'uid'=>$dbUser['id']]);
            if ($manualStmt->fetchColumn() === false) throw new RuntimeException('El informe manual ya no permite modificar la boleta.');
            $boletaPdf = uploadPdf($_FILES['boleta_pdf'] ?? [], 'reports/boletas');
            $existingBoletaStmt = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM monthly_report_files WHERE report_id=:id AND file_type='BOLETA')");
            $existingBoletaStmt->execute(['id' => $reportId]);
            $hasExistingBoleta = (int) $existingBoletaStmt->fetchColumn() === 1;
            if (!$saveManualDraftOnly) {
                $missingManualBoleta = [];
                if ($number === '') $missingManualBoleta[] = 'número de boleta';
                if ($date === '') $missingManualBoleta[] = 'fecha de boleta';
                if ($amount === null || $amount <= 0) $missingManualBoleta[] = 'valor líquido de la boleta';
                if ($boletaPdf === null && !$hasExistingBoleta) $missingManualBoleta[] = 'archivo PDF de la boleta';
                if ($missingManualBoleta !== []) {
                    throw new RuntimeException('Completa los antecedentes de la boleta: ' . implode(', ', $missingManualBoleta) . '.');
                }
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE monthly_reports SET boleta_number=:number,boleta_date=:date,boleta_amount=:amount WHERE id=:id AND honorario_user_id=:uid')
                    ->execute(['number'=>$number !== '' ? $number : null,'date'=>$date !== '' ? $date : null,'amount'=>$amount,'id'=>$reportId,'uid'=>$dbUser['id']]);
                if ($boletaPdf !== null) {
                    $pdo->prepare("DELETE FROM monthly_report_files WHERE report_id=:id AND file_type='BOLETA'")->execute(['id'=>$reportId]);
                    $pdo->prepare("INSERT INTO monthly_report_files (report_id,file_type,original_name,stored_path,mime_type,size_bytes) VALUES (:id,'BOLETA',:name,:path,'application/pdf',:size)")
                        ->execute(['id'=>$reportId,'name'=>$boletaPdf['original_name'],'path'=>$boletaPdf['stored_path'],'size'=>(int)($_FILES['boleta_pdf']['size']??0)]);
                }
                $pdo->commit();
            } catch (Throwable $manualBoletaError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $manualBoletaError;
            }
            if ($saveManualDraftOnly) {
                redirectTo('informe_mensual.php?report_id=' . $reportId . '&step=2&notice=draft_saved#reportWizard');
            }
            redirectTo('informe_mensual.php?report_id=' . $reportId . '&step=3&notice=manual_boleta_saved#reportWizard');
        } elseif ($action === 'send_signature_request') {
            $reportId = (int) ($_POST['report_id'] ?? 0);
            $fileId = (int) ($_POST['file_id'] ?? 0);

            $boletaRequirementStmt = $pdo->prepare("SELECT r.source_type, r.boleta_number, r.boleta_date, r.boleta_amount,
                                                           (SELECT COUNT(*) FROM monthly_report_activities ra WHERE ra.report_id = r.id) AS activities_count,
                                                           EXISTS(SELECT 1 FROM monthly_report_files bf WHERE bf.report_id = r.id AND bf.file_type = 'BOLETA') AS has_boleta_pdf
                                                    FROM monthly_reports r
                                                    WHERE r.id = :id AND r.honorario_user_id = :uid
                                                    LIMIT 1");
            $boletaRequirementStmt->execute(['id' => $reportId, 'uid' => $dbUser['id']]);
            $boletaRequirement = $boletaRequirementStmt->fetch();
            if ($boletaRequirement === false) {
                throw new RuntimeException('Informe no encontrado para enviar a firma.');
            }
            if ((string) ($boletaRequirement['source_type'] ?? '') === 'CONVENIO' && (int) ($boletaRequirement['activities_count'] ?? 0) < 1) {
                throw new RuntimeException('Debes completar las actividades del informe antes de enviarlo a firma.');
            }
            $missingBoletaData = [];
            if (trim((string) ($boletaRequirement['boleta_number'] ?? '')) === '') $missingBoletaData[] = 'número de boleta';
            if (trim((string) ($boletaRequirement['boleta_date'] ?? '')) === '') $missingBoletaData[] = 'fecha de boleta';
            if ((float) ($boletaRequirement['boleta_amount'] ?? 0) <= 0) $missingBoletaData[] = 'valor líquido de la boleta';
            if ((int) ($boletaRequirement['has_boleta_pdf'] ?? 0) !== 1) $missingBoletaData[] = 'archivo PDF de la boleta';
            if ($missingBoletaData !== []) {
                throw new RuntimeException('Antes de firmar debes completar: ' . implode(', ', $missingBoletaData) . '. Guarda nuevamente el informe y luego intenta firmar.');
            }
            $pendingRequestStmt = $pdo->prepare("SELECT id FROM signature_requests WHERE report_id = :id AND status = 'PENDIENTE' LIMIT 1");
            $pendingRequestStmt->execute(['id' => $reportId]);
            if ($pendingRequestStmt->fetchColumn() !== false) {
                throw new RuntimeException('El informe ya fue enviado a firma del funcionario.');
            }
            if ($fileId < 1 && isset($_POST['prepare_convenio_pdf'])) {
                $preparedFile = prepareConvenioReportPdf($pdo, $reportId, (int) $dbUser['id']);
                $fileId = (int) $preparedFile['file_id'];
            }

            $pdo->prepare('UPDATE monthly_reports r INNER JOIN system_users u ON u.id=r.honorario_user_id SET r.direction_id=u.direction_id WHERE r.id=:id AND r.honorario_user_id=:uid')->execute(['id'=>$reportId,'uid'=>$dbUser['id']]);
            $signatureDataStmt = $pdo->prepare('SELECT r.id, r.report_month, r.report_year, r.provider_name, r.direction_id, u.id AS user_id, u.first_names, u.last_names, u.full_name, u.email, f.id AS file_id,
                                                       EXISTS(SELECT 1 FROM signature_requests signed_sr WHERE signed_sr.report_file_id = f.id AND signed_sr.status = \'FIRMADO\') AS is_signed
                                                FROM monthly_reports r
                                                INNER JOIN system_users u ON u.id = r.honorario_user_id
                                                INNER JOIN monthly_report_files f ON f.report_id = r.id AND f.file_type = \'RESPALDO\'
                                                WHERE r.id = :rid AND f.id = :fid AND r.honorario_user_id = :uid
                                                LIMIT 1');
            $signatureDataStmt->execute(['rid' => $reportId, 'fid' => $fileId, 'uid' => $dbUser['id']]);
            $signatureData = $signatureDataStmt->fetch();
            if ($signatureData === false) {
                throw new RuntimeException('No fue posible encontrar el informe que deseas firmar.');
            }

            if ((int) ($signatureData['direction_id'] ?? 0) < 1) {
                throw new RuntimeException('El administrador debe asignarte una dirección antes de enviar el informe.');
            }

            if ((int) ($signatureData['is_signed'] ?? 0) === 1) {
                throw new RuntimeException('Este informe ya fue firmado y no se puede volver a enviar para firma.');
            }

            $recipientEmail = trim((string) ($signatureData['email'] ?? ''));
            if ($recipientEmail === '' || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Debes solicitar al administrador que registre un correo válido en tus datos personales.');
            }

            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');
            $pdo->prepare("UPDATE signature_requests SET status = 'ANULADO' WHERE report_file_id = :fid AND status = 'PENDIENTE'")->execute(['fid' => $fileId]);
            $insertRequest = $pdo->prepare('INSERT INTO signature_requests (report_id, report_file_id, honorario_user_id, recipient_email, token_hash, expires_at)
                                            VALUES (:rid, :fid, :uid, :email, :token, :expires)');
            $insertRequest->execute([
                'rid' => $reportId,
                'fid' => $fileId,
                'uid' => $dbUser['id'],
                'email' => $recipientEmail,
                'token' => $tokenHash,
                'expires' => $expiresAt,
            ]);
            $requestId = (int) $pdo->lastInsertId();

            $signUrl = appUrl('firmar.php?token=' . rawurlencode($rawToken));
            $recipientName = trim((string) ($signatureData['first_names'] ?? '') . ' ' . (string) ($signatureData['last_names'] ?? ''));
            if ($recipientName === '') $recipientName = (string) $signatureData['full_name'];
            $subject = 'Firma de informe mensual - ' . (int) $signatureData['report_month'] . '/' . (int) $signatureData['report_year'];
            $safeName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
            $safeUrl = htmlspecialchars($signUrl, ENT_QUOTES, 'UTF-8');
            $message = '<html><body style="font-family:Arial,sans-serif;color:#17324a">'
                . '<h2>Firma de informe mensual</h2>'
                . '<p>Hola ' . $safeName . ',</p>'
                . '<p>Se ha solicitado tu firma para el informe mensual. Abre el siguiente enlace desde tu teléfono o computador:</p>'
                . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:12px 18px;background:#0b7285;color:#fff;text-decoration:none;border-radius:8px">Revisar y firmar informe</a></p>'
                . '<p>Este enlace es personal, solo puede utilizarse una vez y vence en 24 horas. No lo compartas.</p>'
                . '</body></html>';
            $textMessage = "Hola {$recipientName},\n\nRevisa y firma tu informe en: {$signUrl}\n\nEl enlace vence en 24 horas y solo puede utilizarse una vez.";

            try {
                sendSmtpMail($recipientEmail, $recipientName, $subject, $message, $textMessage);
            } catch (Throwable $mailError) {
                $pdo->prepare("UPDATE signature_requests SET status = 'ANULADO' WHERE id = :id")->execute(['id' => $requestId]);
                throw new RuntimeException('No fue posible enviar el correo SMTP: ' . $mailError->getMessage());
            }

            $pdo->prepare('UPDATE signature_requests SET sent_at = NOW() WHERE id = :id')->execute(['id' => $requestId]);
            $success = 'Enlace seguro de firma enviado a ' . $recipientEmail . '.';
            $selectedReportId = 0;
        } elseif ($action === 'delete_report') {
            $reportId = (int) ($_POST['report_id'] ?? 0);
            if ($reportId < 1) {
                throw new RuntimeException('Informe no válido para eliminar.');
            }

            $storedPaths = [];
            $pdo->beginTransaction();
            try {
                $reportStmt = $pdo->prepare('SELECT r.id, r.status, r.director_signed_at, r.submitted_at,
                                                    EXISTS(SELECT 1 FROM signature_requests sr WHERE sr.report_id = r.id AND (sr.sent_at IS NOT NULL OR sr.status IN (\'PENDIENTE\', \'FIRMADO\', \'EXPIRADO\'))) AS has_signature_request
                                             FROM monthly_reports r
                                             WHERE r.id = :id AND r.honorario_user_id = :uid
                                             LIMIT 1
                                             FOR UPDATE');
                $reportStmt->execute(['id' => $reportId, 'uid' => $dbUser['id']]);
                $reportToDelete = $reportStmt->fetch();
                if ($reportToDelete === false) {
                    throw new RuntimeException('Informe no encontrado para eliminar.');
                }

                if (
                    (string) ($reportToDelete['status'] ?? '') !== 'BORRADOR'
                    || trim((string) ($reportToDelete['submitted_at'] ?? '')) !== ''
                    || (int) ($reportToDelete['has_signature_request'] ?? 0) === 1
                ) {
                    throw new RuntimeException('El informe no se puede borrar porque ya fue enviado a firma.');
                }
                $filePathsStmt = $pdo->prepare('SELECT stored_path FROM monthly_report_files WHERE report_id = :rid');
                $filePathsStmt->execute(['rid' => $reportId]);
                $storedPaths = array_merge($storedPaths, $filePathsStmt->fetchAll(PDO::FETCH_COLUMN));

                $signaturePathsStmt = $pdo->prepare('SELECT signed_signature_path
                                                     FROM signature_requests
                                                     WHERE report_id = :rid AND signed_signature_path IS NOT NULL');
                $signaturePathsStmt->execute(['rid' => $reportId]);
                $storedPaths = array_merge($storedPaths, $signaturePathsStmt->fetchAll(PDO::FETCH_COLUMN));

                $deleteReportStmt = $pdo->prepare('DELETE FROM monthly_reports WHERE id = :id AND honorario_user_id = :uid');
                $deleteReportStmt->execute(['id' => $reportId, 'uid' => $dbUser['id']]);
                if ($deleteReportStmt->rowCount() !== 1) {
                    throw new RuntimeException('No fue posible eliminar el informe.');
                }

                $pdo->commit();
            } catch (Throwable $deleteError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $deleteError;
            }

            $uploadsRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
            foreach (array_unique(array_filter(array_map('strval', $storedPaths))) as $storedPath) {
                $isStillReferenced = false;
                $referenceQueries = [
                    'SELECT 1 FROM monthly_report_files WHERE stored_path = :path LIMIT 1',
                    'SELECT 1 FROM signature_requests WHERE signed_signature_path = :path LIMIT 1',
                    'SELECT 1 FROM agreements WHERE pdf_path = :path LIMIT 1',
                    'SELECT 1 FROM decrees WHERE pdf_path = :path LIMIT 1',
                    'SELECT 1 FROM honorario_signatures WHERE stored_path = :path LIMIT 1',
                    'SELECT 1 FROM director_profiles WHERE signature_path = :path LIMIT 1',
                ];

                try {
                    foreach ($referenceQueries as $referenceQuery) {
                        $referenceStmt = $pdo->prepare($referenceQuery);
                        $referenceStmt->execute(['path' => $storedPath]);
                        if ($referenceStmt->fetchColumn() !== false) {
                            $isStillReferenced = true;
                            break;
                        }
                    }
                } catch (Throwable $cleanupReferenceError) {
                    $isStillReferenced = true;
                }

                if ($isStillReferenced || $uploadsRoot === false) {
                    continue;
                }

                $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storedPath);
                $resolvedPath = realpath($absolutePath);
                if (
                    $resolvedPath !== false
                    && str_starts_with($resolvedPath, $uploadsRoot . DIRECTORY_SEPARATOR)
                    && is_file($resolvedPath)
                ) {
                    @unlink($resolvedPath);
                }
            }

            $selectedReportId = 0;
            $success = 'Informe eliminado correctamente.';
        } elseif ($action === 'delete_pdf') {
            $reportId = (int) ($_POST['report_id'] ?? 0);
            $fileId = (int) ($_POST['file_id'] ?? 0);

            $fileStmt = $pdo->prepare('SELECT f.id, f.stored_path,
                                              EXISTS(SELECT 1 FROM signature_requests sr WHERE sr.report_file_id = f.id AND sr.status = \'FIRMADO\') AS is_signed
                                       FROM monthly_report_files f
                                       INNER JOIN monthly_reports r ON r.id = f.report_id
                                       WHERE f.id = :fid
                                         AND f.report_id = :rid
                                         AND f.file_type = \'RESPALDO\'
                                         AND r.honorario_user_id = :uid
                                       LIMIT 1');
            $fileStmt->execute(['fid' => $fileId, 'rid' => $reportId, 'uid' => $dbUser['id']]);
            $pdfToDelete = $fileStmt->fetch();
            if ($pdfToDelete === false) {
                throw new RuntimeException('PDF no encontrado para eliminar.');
            }

            if ((int) ($pdfToDelete['is_signed'] ?? 0) === 1) {
                throw new RuntimeException('El informe firmado no se puede borrar.');
            }

            $storedPath = (string) $pdfToDelete['stored_path'];
            $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storedPath);
            $uploadsRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
            $resolvedPath = realpath($absolutePath);

            $pdo->prepare('DELETE FROM monthly_report_files WHERE id = :id')->execute(['id' => $fileId]);
            if (
                $uploadsRoot !== false
                && $resolvedPath !== false
                && str_starts_with($resolvedPath, $uploadsRoot . DIRECTORY_SEPARATOR)
                && is_file($resolvedPath)
            ) {
                unlink($resolvedPath);
            }

            $success = 'PDF eliminado. Ahora puedes cargar uno nuevo.';
        } elseif ($action === 'upload_pdf') {
            $reportId = (int) ($_POST['report_id'] ?? 0);

            $reportStmt = $pdo->prepare('SELECT id FROM monthly_reports WHERE id = :id AND honorario_user_id = :uid LIMIT 1');
            $reportStmt->execute(['id' => $reportId, 'uid' => $dbUser['id']]);
            if ($reportStmt->fetch() === false) {
                throw new RuntimeException('Informe no encontrado para adjuntar PDF.');
            }

            $file = uploadPdf($_FILES['report_pdf'] ?? [], 'reports');
            if ($file === null) {
                throw new RuntimeException('Debes seleccionar un PDF para adjuntar.');
            }

            $sizeBytes = isset($_FILES['report_pdf']['size']) ? (int) $_FILES['report_pdf']['size'] : null;
            $mimeType = isset($_FILES['report_pdf']['type']) ? (string) $_FILES['report_pdf']['type'] : null;

            $insertFile = $pdo->prepare('INSERT INTO monthly_report_files (report_id, file_type, original_name, stored_path, mime_type, size_bytes) VALUES (:rid, :type, :oname, :spath, :mime, :size)');
            $insertFile->execute([
                'rid' => $reportId,
                'type' => 'RESPALDO',
                'oname' => (string) $file['original_name'],
                'spath' => (string) $file['stored_path'],
                'mime' => $mimeType,
                'size' => $sizeBytes,
            ]);

            $success = 'PDF del informe adjuntado correctamente.';
        } else {
            throw new RuntimeException('Accion no valida.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($action === 'create_report') {
            $showCreateForm = true;
            $selectedCreateSource = $sourceType ?? $selectedCreateSource;
        } elseif ($action === 'save_activities') {
            $wizardStep = 2;
        } elseif ($action === 'send_signature_request') {
            $wizardStep = 4;
        }
    }
}

$reportsStmt = $pdo->prepare('SELECT
    r.id,
    r.report_month,
    r.report_year,
    r.source_type,
    r.status,
    r.director_signed_at,
    r.finance_approved_at,
    r.finance_rejected_at,
    r.finance_observation,
    r.submitted_at,
    r.created_at,
    r.agreement_id,
    r.provider_name,
    r.profession_experience,
    r.supervision_unit,
    r.program_activity_text,
    r.decree_number_text,
    r.agreement_start_date,
    r.agreement_end_date,
    r.installment_number,
    r.boleta_number,
    r.boleta_date,
    r.boleta_amount,
    r.director_rejection_observation,
    (SELECT COUNT(*) FROM monthly_report_activities a WHERE a.report_id = r.id) AS activities_count,
    (SELECT COUNT(*) FROM signature_requests sr WHERE sr.report_id = r.id AND (sr.sent_at IS NOT NULL OR sr.status IN (\'PENDIENTE\', \'FIRMADO\', \'EXPIRADO\'))) AS signature_requests_count,
    (SELECT COUNT(*) FROM monthly_report_files bf WHERE bf.report_id = r.id AND bf.file_type = \'BOLETA\') AS boleta_file_count,
    (SELECT COUNT(*) FROM monthly_report_files f WHERE f.report_id = r.id AND f.file_type = \'RESPALDO\') AS pdf_count
FROM monthly_reports r
WHERE r.honorario_user_id = :uid
ORDER BY r.report_year DESC, r.report_month DESC, r.id DESC');
$reportsStmt->execute(['uid' => $dbUser['id']]);
$reports = $reportsStmt->fetchAll();

$pdfFilesByReport = [];
if (count($reports) > 0) {
    $pdfFileStmt = $pdo->prepare('SELECT f.id, f.report_id, f.original_name, f.size_bytes,
                                         EXISTS(SELECT 1 FROM signature_requests sr WHERE sr.report_file_id = f.id AND sr.status = \'FIRMADO\') AS is_signed,
                                         EXISTS(SELECT 1 FROM signature_requests sr WHERE sr.report_file_id = f.id AND sr.status = \'PENDIENTE\') AS is_pending_signature
                                  FROM monthly_report_files f
                                  INNER JOIN monthly_reports r ON r.id = f.report_id
                                  WHERE r.honorario_user_id = :uid AND f.file_type = \'RESPALDO\'
                                  ORDER BY f.id DESC');
    $pdfFileStmt->execute(['uid' => $dbUser['id']]);
    foreach ($pdfFileStmt->fetchAll() as $pdfFileRow) {
        $reportKey = (int) $pdfFileRow['report_id'];
        if (!isset($pdfFilesByReport[$reportKey])) {
            $pdfFilesByReport[$reportKey] = $pdfFileRow;
        }
    }
}
$boletaFilesByReport = [];
if (count($reports) > 0) {
    $boletaFileStmt = $pdo->prepare("SELECT f.id, f.report_id, f.original_name, f.stored_path
                                     FROM monthly_report_files f
                                     INNER JOIN monthly_reports r ON r.id = f.report_id
                                     WHERE r.honorario_user_id = :uid AND f.file_type = 'BOLETA'
                                     ORDER BY f.id DESC");
    $boletaFileStmt->execute(['uid' => $dbUser['id']]);
    foreach ($boletaFileStmt->fetchAll() as $boletaFileRow) {
        $reportKey = (int) $boletaFileRow['report_id'];
        if (!isset($boletaFilesByReport[$reportKey])) {
            $boletaFilesByReport[$reportKey] = $boletaFileRow;
        }
    }
}
$reportsById = [];
foreach ($reports as $reportRow) {
    $reportsById[(int) $reportRow['id']] = $reportRow;
}

$selectedReportCanEditActivities = false;
if ($selectedReportId > 0 && isset($reportsById[$selectedReportId])) {
    $selectedReportState = $reportsById[$selectedReportId];
    $selectedReportPdf = $pdfFilesByReport[$selectedReportId] ?? null;
    $selectedStatus = (string) ($selectedReportState['status'] ?? '');
    $selectedHasDirectorSignature = in_array($selectedStatus, ['APROBADO','APROBADO_PAGO'], true)
        || trim((string) ($selectedReportState['director_signed_at'] ?? '')) !== '';
    $selectedHasPendingSignature = $selectedReportPdf !== null
        && (int) ($selectedReportPdf['is_pending_signature'] ?? 0) === 1;
    $selectedHasEmployeeSignature = $selectedReportPdf !== null
        && (int) ($selectedReportPdf['is_signed'] ?? 0) === 1;

    $selectedReportCanEditActivities = $selectedStatus === 'RECHAZADO'
        || (
            !$selectedHasDirectorSignature
            && !$selectedHasPendingSignature
            && !$selectedHasEmployeeSignature
            && $selectedStatus !== 'ENVIADO'
        );
}

$activityRows = [];
if (count($reports) > 0) {
    $reportIds = array_map(static fn (array $row): int => (int) $row['id'], $reports);
    $inReportIds = implode(',', array_fill(0, count($reportIds), '?'));
    $activityStmt = $pdo->prepare('SELECT report_id, function_title, activity_description, sort_order FROM monthly_report_activities WHERE report_id IN (' . $inReportIds . ') ORDER BY report_id ASC, sort_order ASC, id ASC');
    $activityStmt->execute($reportIds);
    foreach ($activityStmt->fetchAll() as $activityRow) {
        $reportKey = (int) $activityRow['report_id'];
        if (!isset($activityRows[$reportKey])) {
            $activityRows[$reportKey] = [];
        }
        $activityRows[$reportKey][] = [
            'function_title' => (string) ($activityRow['function_title'] ?? ''),
            'activity_description' => (string) $activityRow['activity_description'],
        ];
    }
}

$agreementMap = [];
foreach ($agreements as $a) {
    $agreementMap[(int) $a['id']] = (string) $a['agreement_number'];
}

$agreementById = [];
foreach ($agreements as $a) {
    $agreementById[(int) $a['id']] = $a;
}

$agreementFunctionsMap = [];
$agreementFunctionsStmt = $pdo->prepare('SELECT agreement_id, id, function_text FROM agreement_functions WHERE agreement_id IN (SELECT id FROM agreements WHERE honorario_user_id = :uid) ORDER BY agreement_id, sort_order, id');
$agreementFunctionsStmt->execute(['uid' => $dbUser['id']]);
foreach ($agreementFunctionsStmt->fetchAll() as $fnRow) {
    $aid = (int) $fnRow['agreement_id'];
    if (!isset($agreementFunctionsMap[$aid])) {
        $agreementFunctionsMap[$aid] = [];
    }
    $agreementFunctionsMap[$aid][] = [
        'id' => (int) $fnRow['id'],
        'text' => (string) $fnRow['function_text'],
    ];
}

$monthNames = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
               'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

function statusBadge(string $s): string
{
    $map = [
        'BORRADOR'  => ['bg' => '#f1f5f9', 'color' => '#475569', 'label' => 'Borrador'],
        'ENVIADO'   => ['bg' => '#eff6ff', 'color' => '#1d4ed8', 'label' => 'Enviado'],
        'OBSERVADO' => ['bg' => '#fffbeb', 'color' => '#b45309', 'label' => 'Observado'],
        'APROBADO'  => ['bg' => '#f0fdf4', 'color' => '#15803d', 'label' => 'Finalizado'],
        'APROBADO_PAGO' => ['bg' => '#dcfce7', 'color' => '#166534', 'label' => 'Aprobado para pago'],
        'RECHAZADO' => ['bg' => '#fef2f2', 'color' => '#b91c1c', 'label' => 'Rechazado'],
    ];
    $d = $map[$s] ?? ['bg' => '#f1f5f9', 'color' => '#475569', 'label' => htmlspecialchars($s, ENT_QUOTES, 'UTF-8')];
    return '<span style="background:' . $d['bg'] . ';color:' . $d['color'] . ';padding:3px 10px;border-radius:20px;font-size:.8rem;font-weight:600;white-space:nowrap;">' . $d['label'] . '</span>';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Informe mensual | Honorarios</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        :root {
            --primary: #0b7285;
            --primary-hover: #086374;
            --primary-light: #e8f5f8;
            --surface: #ffffff;
            --bg: #f0f4f8;
            --border: #dde6ef;
            --text: #1e3a5f;
            --text-muted: #607d97;
            --radius: 14px;
            --radius-sm: 9px;
            --shadow: 0 1px 4px rgba(11,60,100,.06), 0 6px 20px rgba(11,60,100,.08);
        }

        body {
            font-family: "Segoe UI", system-ui, sans-serif;
            background: var(--bg);
            margin: 0;
            color: var(--text);
            min-height: 100vh;
        }

        .shell {
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
        }

        .sidebar {
            width: 280px;
            min-height: 100vh;
            position: sticky;
            top: 0;
            align-self: flex-start;
            padding: 22px 18px;
            border-right: 1px solid var(--border);
            background: rgba(255,255,255,.84);
            backdrop-filter: blur(10px);
            overflow-y: auto;
        }

        .sidebar-brand {
            margin: 0 0 4px;
            font-size: 1.03rem;
            font-weight: 800;
            color: var(--primary-hover);
        }

        .sidebar-subtitle {
            margin: 0 0 16px;
            color: var(--text-muted);
            font-size: .92rem;
        }

        .sidebar-menu {
            display: grid;
            gap: 8px;
        }

        .sidebar-link {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 11px 12px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
            text-decoration: none;
            font-weight: 700;
            font-size: .92rem;
        }

        .sidebar-link.active {
            background: linear-gradient(120deg, #ecf8fa, #f8fbff);
            border-color: #cfe5ef;
            color: var(--primary-hover);
        }

        .sidebar-tag {
            flex-shrink: 0;
            font-size: .74rem;
            font-weight: 800;
            color: var(--text-muted);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 2px 8px;
            white-space: nowrap;
        }

        .content-shell {
            flex: 1;
            min-width: 0;
        }

        /* ── Topbar ── */
        .topbar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            height: 60px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .topbar-brand {
            font-weight: 700;
            font-size: 1rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .topbar-brand svg { flex-shrink: 0; }
        .topbar-nav { display: flex; gap: 8px; align-items: center; }

        /* ── Buttons ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--primary);
            color: #fff;
            text-decoration: none;
            padding: 9px 18px;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: .9rem;
            transition: background .15s, transform .1s;
            white-space: nowrap;
        }
        .btn:hover { background: var(--primary-hover); }
        .btn:active { transform: scale(.97); }
        .btn-ghost {
            background: transparent;
            color: var(--primary);
            border: 1.5px solid var(--border);
        }
        .btn-ghost:hover { background: var(--primary-light); }
        .btn-sm { padding: 6px 12px; font-size: .82rem; }
        .btn-muted { background: #e2eaf2; color: #607d97; cursor: default; }
        .btn-muted:hover { background: #e2eaf2; }

        /* ── Page layout ── */
        .page { max-width: 1100px; margin: 28px auto; padding: 0 20px 48px; }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .page-header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .page-title { margin: 0; font-size: 1.55rem; font-weight: 700; color: var(--text); }
        .page-subtitle { margin: 4px 0 0; color: var(--text-muted); font-size: .9rem; }

        /* ── Alerts ── */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            font-size: .92rem;
            font-weight: 500;
        }
        .alert-ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .alert-err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        /* ── Cards ── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 24px;
            overflow: hidden;
        }
        .create-report-card {
            display: none;
        }
        .create-report-card.is-visible {
            display: block;
        }
        .card-header {
            padding: 18px 24px 14px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .card-header h2 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card-header h2 .step {
            width: 26px; height: 26px;
            background: var(--primary);
            color: #fff;
            border-radius: 50%;
            font-size: .78rem;
            font-weight: 700;
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }
        .card-body { padding: 22px 24px; }
        .report-activity-card {
            border-top: 3px solid var(--primary);
        }
        .modal-bg {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(10, 26, 43, .52);
            z-index: 220;
        }
        .pdf-preview-box {
            width: min(1100px, 96vw);
            height: min(850px, 92vh);
            display: flex;
            flex-direction: column;
        }
        .pdf-preview-frame {
            width: 100%;
            flex: 1;
            min-height: 0;
            border: 0;
            background: #eef2f6;
        }
        .pdf-preview-body {
            display: flex;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }
        .pdf-preview-footer {
            display: none;
            justify-content: flex-end;
            padding: 14px 18px;
            border-top: 1px solid var(--border);
            background: #fff;
        }
        .pdf-preview-footer.is-visible {
            display: flex;
        }
        .modal-box {
            width: min(760px, 100%);
            background: #fff;
            border-radius: 18px;
            border: 1px solid var(--border);
            box-shadow: 0 24px 60px rgba(6, 22, 38, .26);
            overflow: hidden;
        }
        .modal-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 20px 12px;
            border-bottom: 1px solid var(--border);
        }
        .modal-head h3 {
            margin: 0;
            font-size: 1.02rem;
            font-weight: 800;
            color: var(--text);
        }
        .modal-head p {
            margin: 6px 0 0;
            color: var(--text-muted);
            font-size: .9rem;
            line-height: 1.4;
        }
        .modal-close {
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text-muted);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
            flex: 0 0 auto;
        }
        .modal-body {
            padding: 18px 20px 20px;
        }
        .choice-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 4px;
        }
        .choice-card {
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px;
            background: #fff;
            text-align: left;
            cursor: pointer;
            transition: transform .12s ease, border-color .12s ease, box-shadow .12s ease;
            width: 100%;
        }
        .choice-card:hover {
            transform: translateY(-1px);
            border-color: #b8d8e4;
            box-shadow: 0 8px 22px rgba(11,60,100,.08);
        }
        .choice-card h4 {
            margin: 0 0 6px;
            font-size: 1rem;
            color: var(--text);
        }
        .choice-card p {
            margin: 0;
            color: var(--text-muted);
            font-size: .86rem;
            line-height: 1.35;
        }
        .choice-emoji {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: inline-grid;
            place-items: center;
            background: var(--primary-light);
            color: var(--primary-hover);
            font-weight: 800;
            margin-bottom: 10px;
        }
        .modal-step[hidden] { display: none; }
        .agreement-choice-list {
            display: grid;
            gap: 10px;
            max-height: min(420px, 55vh);
            overflow-y: auto;
            padding: 2px;
        }
        .agreement-choice {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            width: 100%;
            padding: 14px 16px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #fff;
            color: var(--text);
            text-align: left;
            cursor: pointer;
            transition: border-color .12s ease, box-shadow .12s ease, transform .12s ease;
        }
        .agreement-choice:hover,
        .agreement-choice:focus-visible {
            transform: translateY(-1px);
            border-color: #8fc4d3;
            box-shadow: 0 8px 22px rgba(11, 60, 100, .08);
            outline: none;
        }
        .agreement-choice strong,
        .agreement-choice small { display: block; }
        .agreement-choice small {
            margin-top: 4px;
            color: var(--text-muted);
        }
        .agreement-choice-action {
            flex: 0 0 auto;
            color: var(--primary);
            font-weight: 800;
        }
        .modal-step-actions {
            display: flex;
            justify-content: flex-start;
            margin-top: 16px;
        }
        .empty-agreements {
            padding: 22px;
            border: 1px dashed var(--border);
            border-radius: 14px;
            background: #f8fafc;
            color: var(--text-muted);
            text-align: center;
        }
        .report-wizard-progress {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 24px;
        }
        .report-wizard-step {
            position: relative;
            display: flex;
            align-items: center;
            gap: 9px;
            min-height: 58px;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #f8fafc;
            color: var(--text-muted);
            text-decoration: none;
        }
        .report-wizard-step.is-active {
            border-color: #8fc4d3;
            background: var(--primary-light);
            color: var(--primary-hover);
            box-shadow: inset 0 0 0 1px rgba(8, 99, 116, .08);
        }
        .report-wizard-step.is-complete {
            border-color: #b9dec9;
            background: #f0fdf4;
            color: #166534;
        }
        .report-wizard-step.is-active {
            border-color: #8fc4d3;
            background: var(--primary-light);
            color: var(--primary-hover);
        }
        .report-wizard-number {
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #dbe5ec;
            color: #40576a;
            font-size: .78rem;
            font-weight: 800;
        }
        .is-active .report-wizard-number {
            background: var(--primary);
            color: #fff;
        }
        .is-complete .report-wizard-number {
            background: #15803d;
            color: #fff;
        }
        .report-wizard-label strong,
        .report-wizard-label small { display: block; }
        .report-wizard-label strong {
            font-size: .82rem;
            line-height: 1.2;
        }
        .report-wizard-label small {
            margin-top: 3px;
            font-size: .7rem;
            line-height: 1.2;
            color: inherit;
            opacity: .82;
        }
        .wizard-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        .wizard-summary-item {
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #f8fafc;
        }
        .wizard-summary-item span,
        .wizard-summary-item strong { display: block; }
        .wizard-summary-item span {
            margin-bottom: 4px;
            color: var(--text-muted);
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .wizard-summary-item strong {
            color: var(--text);
            font-size: .88rem;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }
        .wizard-review-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(280px, .8fr);
            gap: 18px;
        }
        .wizard-review-panel {
            padding: 18px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #fff;
        }
        .wizard-review-panel h3 { margin: 0 0 12px; font-size: 1rem; }
        .wizard-check-list { display: grid; gap: 9px; margin: 0; padding: 0; list-style: none; }
        .wizard-check-list li {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            color: var(--text);
            font-size: .88rem;
        }
        .wizard-check-icon {
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            width: 21px;
            height: 21px;
            border-radius: 50%;
            background: #dcfce7;
            color: #166534;
            font-size: .72rem;
            font-weight: 900;
        }
        .wizard-check-icon.is-missing { background: #fee2e2; color: #b42318; }
        .wizard-sign-panel {
            max-width: 680px;
            margin: 0 auto;
            padding: 26px;
            border: 1px solid #b8d8e4;
            border-radius: 16px;
            background: linear-gradient(145deg, #f7fcfd, #eef8fb);
            text-align: center;
        }
        .wizard-sign-panel h3 { margin: 0 0 8px; color: var(--text); }
        .wizard-sign-panel p { margin: 0 0 18px; color: var(--text-muted); line-height: 1.5; }
        .manual-create-boleta-field { display: none; }
        @media (max-width: 900px) {
            .report-wizard-progress { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .wizard-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .wizard-review-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 560px) {
            .report-wizard-progress,
            .wizard-summary { grid-template-columns: 1fr; }
            .report-wizard-label small { display: none; }
            .report-wizard-step { min-height: 48px; }
        }

        /* ── Form ── */
        .field-group {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }
        .field-group-2 { grid-template-columns: repeat(2, 1fr); }
        .field-group-3 { grid-template-columns: repeat(3, 1fr); }
        .field-group-4 { grid-template-columns: repeat(4, 1fr); }
        .col-span-2 { grid-column: span 2; }
        .col-span-3 { grid-column: span 3; }

        .field { display: flex; flex-direction: column; gap: 5px; }
        .field label {
            font-size: .78rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .field input,
        .field select,
        .field textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: .92rem;
            color: var(--text);
            background: #fff;
            transition: border-color .15s, box-shadow .15s;
            outline: none;
            font-family: inherit;
        }
        .field input:focus,
        .field select:focus,
        .field textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(11,114,133,.12);
        }
        .field textarea { min-height: 90px; resize: vertical; }
        .field select { cursor: pointer; }

        /* ── Source type toggle ── */
        .source-toggle {
            display: flex;
            gap: 0;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            overflow: hidden;
            background: #f8fafc;
        }
        .source-toggle label {
            flex: 1;
            text-align: center;
            padding: 9px 10px;
            font-size: .85rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s, color .15s;
            color: var(--text-muted);
            user-select: none;
        }
        .source-toggle input[type="radio"] { display: none; }
        .source-toggle input[type="radio"]:checked + label {
            background: var(--primary);
            color: #fff;
        }

        /* ── Section separator ── */
        .form-section-title {
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--text-muted);
            margin: 4px 0 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border);
        }

        /* ── Form footer ── */
        .form-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        .form-footer-note {
            font-size: .83rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .activity-field-stack {
            display: grid;
            gap: 12px;
        }

        .activity-textarea {
            min-height: 120px;
        }

        .manual-row-header {
            display: flex;
            align-items: flex-end;
            gap: 10px;
        }

        .manual-row-header .field {
            flex: 1;
        }

        .manual-row-remove {
            flex: 0 0 auto;
            width: 34px;
            height: 34px;
            border: 1px solid #f3c2c2;
            border-radius: 999px;
            background: #fff1f1;
            color: #b42318;
            font-size: 1rem;
            font-weight: 800;
            line-height: 1;
            cursor: pointer;
        }

        .manual-row-remove:hover {
            background: #ffe2e2;
            border-color: #e59a9a;
        }

        .source-panel {
            display: none;
            margin-top: 12px;
            gap: 10px;
        }

        .source-panel.is-visible {
            display: grid;
        }

        /* ── Table ── */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        thead tr { background: #f8fafc; }
        th {
            padding: 11px 16px;
            text-align: left;
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        td {
            padding: 13px 16px;
            border-bottom: 1px solid #f0f4f8;
            color: var(--text);
            vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: #f8fafc; }
        .td-period { font-weight: 600; white-space: nowrap; }
        .td-origin {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: .78rem;
            font-weight: 600;
            padding: 3px 9px;
            border-radius: 20px;
        }
        .origin-convenio { background: #eff6ff; color: #1d4ed8; }
        .origin-manual   { background: #f5f3ff; color: #6d28d9; }
        .td-empty {
            text-align: center;
            padding: 36px;
            color: var(--text-muted);
            font-size: .92rem;
        }
        .options-note {
            margin: 14px 0 18px;
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: #f8fafc;
            display: grid;
            gap: 8px;
        }
        .options-note p {
            margin: 0;
            font-size: .86rem;
            color: #334e68;
            line-height: 1.35;
        }
        .actions-cell {
            min-width: 360px;
        }
        .action-stack {
            display: grid;
            gap: 8px;
        }
        .action-box {
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 10px;
            background: #ffffff;
        }
        .action-box h4 {
            margin: 0 0 6px;
            font-size: .82rem;
            color: #334e68;
        }
        .action-box p {
            margin: 0 0 8px;
            font-size: .78rem;
            color: #627d98;
            line-height: 1.35;
        }
        .functions-list {
            display: grid;
            gap: 6px;
            margin-bottom: 8px;
            max-height: 140px;
            overflow: auto;
            padding-right: 4px;
        }
        .fn-check {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            font-size: .78rem;
            color: #334e68;
        }
        .fn-check input {
            margin-top: 2px;
        }
        .upload-inline {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
        }
        .upload-inline input[type="file"] {
            max-width: 220px;
            font-size: .76rem;
        }

        .uploaded-pdf-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }
        .uploaded-pdf-info {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            color: #334e68;
            font-size: .82rem;
            font-weight: 600;
        }
        .uploaded-pdf-name {
            max-width: 230px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .uploaded-pdf-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .btn-delete-pdf {
            border-color: #f3c2c2;
            background: #fff1f1;
            color: #b42318;
        }
        .btn-delete-pdf:hover { background: #ffe2e2; }
        /* ── Responsive ── */
        @media (max-width: 860px) {
            .shell { display: block; }
            .sidebar {
                width: 100%;
                min-height: auto;
                position: relative;
                border-right: 0;
                border-bottom: 1px solid var(--border);
            }
            .field-group, .field-group-3, .field-group-4 { grid-template-columns: 1fr 1fr; }
            .col-span-2, .col-span-3 { grid-column: span 2; }
        }
        @media (max-width: 540px) {
            .field-group, .field-group-2, .field-group-3, .field-group-4 { grid-template-columns: 1fr; }
            .col-span-2, .col-span-3 { grid-column: span 1; }
            .topbar-nav .btn-ghost { display: none; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <aside class="sidebar">
            <h2 class="sidebar-brand">Personal a Honorarios</h2>
            <p class="sidebar-subtitle">Panel del perfil Honorario</p>
            <nav class="sidebar-menu" aria-label="Menu principal">
                <a class="sidebar-link" href="dashboard.php">Inicio <span class="sidebar-tag">Home</span></a>
                <a class="sidebar-link" href="convenios.php">Mis convenios <span class="sidebar-tag">Activo</span></a>
                <a class="sidebar-link" href="decretos.php">Mis decretos <span class="sidebar-tag">Activo</span></a>
                <a class="sidebar-link active" href="informe_mensual.php">Informe mensual <span class="sidebar-tag">Prioridad</span></a>
                <a class="sidebar-link" href="#">Carga PDF firmado <span class="sidebar-tag">Prox.</span></a>
                <a class="sidebar-link" href="#">Historial <span class="sidebar-tag">Prox.</span></a>
            </nav>
        </aside>

        <div class="content-shell">
            <header class="topbar">
                <a class="topbar-brand" href="dashboard.php">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                    Honorarios
                </a>
                <nav class="topbar-nav">
                    <a class="btn btn-ghost btn-sm" href="dashboard.php">Dashboard</a>
                    <a class="btn btn-ghost btn-sm" href="convenios.php">Convenios</a>
                    <a class="btn btn-ghost btn-sm" href="decretos.php">Decretos</a>
                </nav>
            </header>

            <main class="page">

                <div class="page-header">
                    <div>
                        <h1 class="page-title">Informe mensual</h1>
                        <p class="page-subtitle">Registra y gestiona tus informes de actividades mensuales</p>
                    </div>
                    <div class="page-header-actions">
                        <button class="btn" type="button" id="openCreateChoiceBtn" aria-controls="createChoiceModal" aria-expanded="false">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14m-7-7l7 7 7-7"/></svg>
                            Crear informe
                        </button>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                <div class="alert alert-ok">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                <div class="alert alert-err">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php endif; ?>

        <!-- Formulario -->
        <div class="card create-report-card<?php echo $showCreateForm ? ' is-visible' : ''; ?>" id="createReportCard"<?php echo $showCreateForm ? ' style="display:block;"' : ''; ?> data-selected-source="<?php echo htmlspecialchars($selectedCreateSource, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="card-header">
                <h2><span class="step">1</span> Crear nuevo informe</h2>
                <span class="create-source-pill" id="createSourcePill">Modalidad: <?php echo $selectedCreateSource === 'MANUAL' ? 'Manual con PDF' : 'Convenio almacenado'; ?></span>
            </div>
            <div class="card-body">
                <div class="report-wizard-progress" id="createWizardProgress">
                    <div class="report-wizard-step is-active">
                        <span class="report-wizard-number">1</span>
                        <span class="report-wizard-label"><strong>Datos del informe</strong><small>Periodo y convenio</small></span>
                    </div>
                    <div class="report-wizard-step">
                        <span class="report-wizard-number">2</span>
                        <span class="report-wizard-label"><strong>Actividades y boleta</strong><small>Funciones y respaldo</small></span>
                    </div>
                    <div class="report-wizard-step">
                        <span class="report-wizard-number">3</span>
                        <span class="report-wizard-label"><strong>Revisión</strong><small>Validación y vista previa</small></span>
                    </div>
                    <div class="report-wizard-step">
                        <span class="report-wizard-number">4</span>
                        <span class="report-wizard-label"><strong>Firmar y enviar</strong><small>Envío al director</small></span>
                    </div>
                </div>
                <form method="post" enctype="multipart/form-data" id="reportForm">
                    <input type="hidden" name="action" value="create_report">

                    <p class="form-section-title">Datos del periodo y prestador</p>
                    <div class="field-group field-group-4">
                        <div class="field">
                            <label>Mes</label>
                            <select name="report_month" required>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m === (int) date('n') ? 'selected' : ''; ?>>
                                        <?php echo $monthNames[$m]; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Año</label>
                            <input type="number" min="2020" max="2100" name="report_year" required value="<?php echo (int) date('Y'); ?>">
                        </div>
                        <div class="field">
                            <label>Nombre del prestador</label>
                            <input name="provider_name" required value="<?php echo htmlspecialchars((string) $dbUser['full_name'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="field">
                            <label>Profesión / Oficio / Experiencia</label>
                            <input name="profession_experience" required value="<?php echo htmlspecialchars((string) ($dbUser['profession_experience'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="field">
                            <label>Dirección o unidad de supervisión</label>
                            <input name="supervision_unit" id="supervisionInput" required readonly value="<?php echo htmlspecialchars($configuredDirectionName !== '' ? $configuredDirectionName : 'Sin dirección asignada', ENT_QUOTES, 'UTF-8'); ?>"><small>Dirección asignada por el administrador.</small>
                        </div>
                    </div>

                    <p class="form-section-title">Origen del informe</p>
                    <div class="field-group field-group-4">
                        <div class="field">
                            <label>Tipo de origen</label>
                            <div class="source-toggle">
                                <input type="radio" name="source_type" id="srcConvenio" value="CONVENIO" <?php echo $selectedCreateSource === 'CONVENIO' ? 'checked' : ''; ?>>
                                <label for="srcConvenio">Convenio</label>
                                <input type="radio" name="source_type" id="srcManual" value="MANUAL" <?php echo $selectedCreateSource === 'MANUAL' ? 'checked' : ''; ?>>
                                <label for="srcManual">Manual</label>
                            </div>
                        </div>
                        <div class="field" id="agreementSelectWrap">
                            <label>Convenio</label>
                            <select name="agreement_id" id="agreementSelect">
                                <option value="">— Seleccionar —</option>
                                <?php foreach ($activeAgreements as $a): ?>
                                    <option
                                        value="<?php echo (int) $a['id']; ?>"
                                        data-program="<?php echo htmlspecialchars((string) $a['program_item'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-profession="<?php echo htmlspecialchars((string) ($a['profession_experience'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-supervision="<?php echo htmlspecialchars((string) ($a['supervision_unit'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-decree="<?php echo htmlspecialchars((string) ($a['decree_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-start="<?php echo htmlspecialchars((string) $a['start_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-end="<?php echo htmlspecialchars((string) $a['end_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-installments="<?php echo htmlspecialchars((string) ($a['installments_total'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-next-installment="<?php echo htmlspecialchars((string) ($a['next_installment'] ?? 1), ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <?php echo htmlspecialchars((string) $a['agreement_number'] . ' — ' . (string) $a['program_item'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>N° decreto aprobatorio</label>
                            <input name="decree_number_text" id="decreeInput" placeholder="Ej: DEC-2026-101">
                        </div>
                        <div class="field">
                            <label>N° cuota (si aplica)</label>
                            <input type="number" min="1" name="installment_number" id="installmentInput" placeholder="Ej: 3">
                        </div>
                        <div class="field manual-create-boleta-field" style="display:none;">
                            <label>N° boleta</label>
                            <input name="boleta_number" id="boletaNumberInput" placeholder="Ej: BOL-2026-041">
                        </div>
                        <div class="field manual-create-boleta-field" style="display:none;">
                            <label>Fecha de boleta</label>
                            <input type="date" name="boleta_date" id="boletaDateInput">
                        </div>
                        <div class="field manual-create-boleta-field" style="display:none;">
                            <label>Valor líquido de la boleta</label>
                            <input type="number" min="0" step="1" name="boleta_amount" id="boletaAmountInput" placeholder="Ej: 805125">
                        </div>
                    </div>

                    <div class="source-panel<?php echo $selectedCreateSource === 'MANUAL' ? ' is-visible' : ''; ?>" id="manualPdfPanel">
                            <div class="field">
                                <label>PDF del informe</label>
                                <input type="file" name="report_pdf_manual" id="reportPdfManual" accept="application/pdf">
                            </div>
                            <div class="field">
                                <label>PDF del convenio</label>
                                <input type="file" name="agreement_pdf_manual" id="agreementPdfManual" accept="application/pdf">
                            </div>
                            <div class="field">
                                <label>PDF del decreto</label>
                                <input type="file" name="decree_pdf_manual" id="decreePdfManual" accept="application/pdf">
                            </div>
                            <div class="field" style="display:none;">
                                <label>PDF de la boleta</label>
                                <input type="file" name="boleta_pdf_manual" id="boletaPdfManual" accept="application/pdf">
                            </div>
                            <div class="field" style="padding: 12px 0 0;">
                                <label style="display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;font-size:.88rem;font-weight:700;color:var(--text);">
                                    <input type="checkbox" name="save_profile_records" id="saveProfileRecords">
                                    Guardar convenio y decreto en mi perfil
                                </label>
                                <p class="form-footer-note" style="margin:8px 0 0;display:block;">
                                    Si marcas esta opción, el convenio y decreto se almacenarán para reutilizarlos en futuros informes.
                                </p>
                            </div>
                            <div id="manualProfileFields" class="source-panel" style="margin-top:6px; display:none;">
                                <div class="field-group field-group-3">
                                    <div class="field">
                                        <label>N° convenio</label>
                                        <input name="agreement_number_manual" id="agreementNumberManual" placeholder="Ej: CONV-2026-014">
                                    </div>
                                    <div class="field">
                                        <label>Fecha convenio</label>
                                        <input type="date" name="agreement_date_manual" id="agreementDateManual">
                                    </div>
                                    <div class="field">
                                        <label>Total de cuotas</label>
                                        <input type="number" min="1" name="installments_total_manual" id="installmentsTotalManual" placeholder="Ej: 12">
                                    </div>
                                </div>
                                <div class="field-group field-group-2">
                                    <div class="field">
                                        <label>Fecha decreto</label>
                                        <input type="date" name="decree_date_manual" id="decreeDateManual">
                                    </div>
                                    <div class="field">
                                        <label>Referencia para guardar en perfil</label>
                                        <input value="Usa el N° de decreto indicado arriba" readonly>
                                    </div>
                                </div>
                            </div>
                        <p class="form-footer-note" style="margin:0;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                En la modalidad manual, puedes adjuntar el informe, convenio, decreto y boleta desde este mismo formulario.
                        </p>
                    </div>

                    <p class="form-section-title">Detalles del convenio</p>
                    <div class="field-group field-group-4">
                        <div class="field col-span-2">
                            <label>Programa, convenio y/o actividad</label>
                            <textarea name="program_activity_text" id="programInput" required placeholder="Describe el programa o actividad principal..."></textarea>
                        </div>
                        <div class="field">
                            <label>Vigencia inicio</label>
                            <input type="date" name="agreement_start_date" id="startInput" required>
                        </div>
                        <div class="field">
                            <label>Vigencia fin</label>
                            <input type="date" name="agreement_end_date" id="endInput" required>
                        </div>
                    </div>

                    <div class="form-footer">
                        <button class="btn btn-ghost" type="button" id="cancelCreateReportBtn">Cancelar</button>
                        <span class="form-footer-note">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            El informe se guardará en estado Borrador. La carga de actividades queda para la próxima etapa.
                        </span>
                        <button class="btn" type="submit">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14m-7-7l7 7 7-7"/></svg>
                            <span id="createReportButtonText">Guardar y continuar</span>
                        </button>
                    </div>

                </form>
            </div>
        </div>

        <div class="modal-bg" id="createChoiceModal" aria-hidden="true">
            <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="createChoiceTitle">
                <div class="modal-head">
                    <div>
                        <h3 id="createChoiceTitle">¿Qué tipo de informe deseas crear?</h3>
                        <p>Elige si el informe se relaciona a un convenio almacenado o si cargarás un PDF ya preparado.</p>
                    </div>
                    <button class="modal-close" type="button" id="closeCreateChoiceModal" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    <div class="modal-step" id="createTypeStep">
                        <div class="choice-grid">
                            <button class="choice-card" type="button" data-open-agreement-step>
                                <span class="choice-emoji">C</span>
                                <h4>Relacionarlo a un convenio</h4>
                                <p>Selecciona un convenio vigente y autocompleta sus antecedentes.</p>
                            </button>
                            <button class="choice-card" type="button" data-create-source="MANUAL">
                                <span class="choice-emoji">P</span>
                                <h4>Cargar informe en PDF</h4>
                                <p>Ingresa los datos manualmente y adjunta el PDF del informe en el mismo formulario.</p>
                            </button>
                        </div>
                    </div>
                    <div class="modal-step" id="createAgreementStep" hidden>
                        <?php if ($activeAgreements !== []): ?>
                            <div class="agreement-choice-list">
                                <?php foreach ($activeAgreements as $agreement): ?>
                                    <button class="agreement-choice" type="button" data-select-agreement="<?php echo (int) $agreement['id']; ?>">
                                        <span>
                                            <strong><?php echo htmlspecialchars((string) $agreement['agreement_number'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <small><?php echo htmlspecialchars((string) $agreement['program_item'], ENT_QUOTES, 'UTF-8'); ?></small>
                                            <small>Vigente hasta <?php echo htmlspecialchars(date('d-m-Y', strtotime((string) $agreement['end_date'])), ENT_QUOTES, 'UTF-8'); ?></small>
                                        </span>
                                        <span class="agreement-choice-action">Seleccionar</span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-agreements">No tienes convenios vigentes disponibles para crear un informe.</div>
                        <?php endif; ?>
                        <div class="modal-step-actions">
                            <button class="btn btn-ghost" type="button" id="backToCreateTypeBtn">Volver atrás</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-bg" id="pdfPreviewModal" aria-hidden="true">
            <div class="modal-box pdf-preview-box" role="dialog" aria-modal="true" aria-labelledby="pdfPreviewTitle">
                <div class="modal-head">
                    <div>
                        <h3 id="pdfPreviewTitle">Informe</h3>
                        <p>Documento PDF cargado.</p>
                    </div>
                    <button class="modal-close" type="button" id="closePdfPreviewModal" aria-label="Cerrar vista previa">×</button>
                </div>
                <div class="pdf-preview-body">
                    <iframe class="pdf-preview-frame" id="pdfPreviewFrame" title="Vista previa del informe PDF"></iframe>
                </div>
                <div class="pdf-preview-footer" id="pdfPreviewFooter">
                    <form method="post" id="previewSignForm" data-signature-request-form>
                        <input type="hidden" name="action" value="send_signature_request">
                        <input type="hidden" name="report_id" id="previewSignReportId" value="">
                        <input type="hidden" name="file_id" value="0">
                        <input type="hidden" name="prepare_convenio_pdf" value="1">
                        <button class="btn" type="submit">Firmar</button>
                    </form>
                </div>
            </div>
        </div>
        <?php if (
            $selectedReportId > 0
            && isset($reportsById[$selectedReportId])
            && $selectedReportCanEditActivities
        ): ?>
        <?php
            $selectedReport = $reportsById[$selectedReportId];
            $selectedIsConvenio = (string) ($selectedReport['source_type'] ?? '') === 'CONVENIO';
            $selectedAgreementId = (int) ($selectedReport['agreement_id'] ?? 0);
            $selectedAgreement = $selectedAgreementId > 0 ? ($agreementById[$selectedAgreementId] ?? null) : null;
            $selectedFunctions = $selectedAgreementId > 0 ? ($agreementFunctionsMap[$selectedAgreementId] ?? []) : [];
            $selectedActivities = $activityRows[$selectedReportId] ?? [];
            $selectedBoletaFile = $boletaFilesByReport[$selectedReportId] ?? null;
            $selectedMissingBoletaFields = [];
            if (trim((string) ($selectedReport['boleta_number'] ?? '')) === '') $selectedMissingBoletaFields[] = 'número de boleta';
            if (trim((string) ($selectedReport['boleta_date'] ?? '')) === '') $selectedMissingBoletaFields[] = 'fecha de boleta';
            if ((float) ($selectedReport['boleta_amount'] ?? 0) <= 0) $selectedMissingBoletaFields[] = 'valor líquido de la boleta';
            if ($selectedBoletaFile === null) $selectedMissingBoletaFields[] = 'archivo PDF de la boleta';
            $selectedMissingBoletaMessage = implode(', ', $selectedMissingBoletaFields);
            $installmentsTotal = $selectedAgreement !== null ? (int) ($selectedAgreement['installments_total'] ?? 0) : 0;
            $selectedReportPdf = $pdfFilesByReport[$selectedReportId] ?? null;
            $selectedActivitiesComplete = !$selectedIsConvenio || (
                count($selectedFunctions) > 0
                && count($selectedActivities) === count($selectedFunctions)
                && count(array_filter($selectedActivities, static fn (array $activity): bool => trim((string) ($activity['activity_description'] ?? '')) !== '')) === count($selectedFunctions)
            );
            $selectedReadyForReview = $selectedActivitiesComplete
                && $selectedMissingBoletaFields === []
                && ($selectedIsConvenio || $selectedReportPdf !== null);
            $selectedAgreementNumber = $selectedIsConvenio ? (string) ($selectedAgreement['agreement_number'] ?? 'Convenio asociado') : 'Informe manual';
            $wizardStep = max(2, $wizardStep);
        ?>
        <div class="card report-activity-card" id="reportWizard">
            <div class="card-header">
                <h2>Preparar informe <?php echo $selectedIsConvenio ? 'por convenio' : 'manual'; ?></h2>
                <a class="btn btn-ghost btn-sm" href="dashboard.php" title="Volver a la portada">Cerrar</a>
            </div>
            <div class="card-body">
                <div class="report-wizard-progress" aria-label="Progreso del informe">
                    <div class="report-wizard-step is-complete<?php echo $wizardStep === 1 ? ' is-active' : ''; ?>">
                        <span class="report-wizard-number">✓</span>
                        <span class="report-wizard-label"><strong>Datos del informe</strong><small>Guardados</small></span>
                    </div>
                    <a class="report-wizard-step<?php echo $wizardStep === 2 ? ' is-active' : ''; ?><?php echo $selectedReadyForReview ? ' is-complete' : ''; ?>" href="?report_id=<?php echo (int) $selectedReportId; ?>&amp;step=2#reportWizard">
                        <span class="report-wizard-number"><?php echo $selectedReadyForReview ? '✓' : '2'; ?></span>
                        <span class="report-wizard-label"><strong>Actividades y boleta</strong><small><?php echo $selectedIsConvenio ? 'Completar antecedentes' : 'Antecedentes de la boleta'; ?></small></span>
                    </a>
                    <a class="report-wizard-step<?php echo $wizardStep === 3 ? ' is-active' : ''; ?><?php echo $wizardStep > 3 && $selectedReadyForReview ? ' is-complete' : ''; ?>" href="?report_id=<?php echo (int) $selectedReportId; ?>&amp;step=3#reportWizard">
                        <span class="report-wizard-number"><?php echo $wizardStep > 3 && $selectedReadyForReview ? '✓' : '3'; ?></span>
                        <span class="report-wizard-label"><strong>Revisión</strong><small>Validar y visualizar</small></span>
                    </a>
                    <a class="report-wizard-step<?php echo $wizardStep === 4 ? ' is-active' : ''; ?>" href="?report_id=<?php echo (int) $selectedReportId; ?>&amp;step=4#reportWizard">
                        <span class="report-wizard-number">4</span>
                        <span class="report-wizard-label"><strong>Firmar y enviar</strong><small>Enviar enlace de firma</small></span>
                    </a>
                </div>

                <?php if ($wizardStep === 2): ?>
                <div class="wizard-summary">
                    <div class="wizard-summary-item"><span>Periodo</span><strong><?php echo htmlspecialchars(($monthNames[(int) $selectedReport['report_month']] ?? '') . ' de ' . (string) $selectedReport['report_year'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div class="wizard-summary-item"><span>Dirección</span><strong><?php echo htmlspecialchars((string) $selectedReport['supervision_unit'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div class="wizard-summary-item"><span><?php echo $selectedIsConvenio ? 'Convenio' : 'Modalidad'; ?></span><strong><?php echo htmlspecialchars($selectedAgreementNumber, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div class="wizard-summary-item"><span>Cuota</span><strong><?php echo $selectedIsConvenio ? 'N.º ' . (int) ($selectedReport['installment_number'] ?? 0) : 'No aplica'; ?></strong></div>
                </div>

                <?php if ($selectedIsConvenio): ?>
                <form method="post" enctype="multipart/form-data" id="wizardActivitiesForm">
                    <input type="hidden" name="action" value="save_activities">
                    <input type="hidden" name="report_id" value="<?php echo (int) $selectedReportId; ?>">
                    <input type="hidden" name="profession_experience" value="<?php echo htmlspecialchars((string) ($selectedReport['profession_experience'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="program_activity_text" value="<?php echo htmlspecialchars((string) ($selectedReport['program_activity_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="decree_number_text" value="<?php echo htmlspecialchars((string) ($selectedReport['decree_number_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="agreement_start_date" value="<?php echo htmlspecialchars((string) ($selectedReport['agreement_start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="agreement_end_date" value="<?php echo htmlspecialchars((string) ($selectedReport['agreement_end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ((int) ($selectedReport['installment_number'] ?? 0) > 0): ?>
                        <input type="hidden" name="installment_number" value="<?php echo (int) $selectedReport['installment_number']; ?>">
                    <?php else: ?>
                        <div class="field" style="max-width:260px;margin-bottom:18px;">
                            <label>N.º de cuota pendiente</label>
                            <select name="installment_number" required>
                                <option value="">Seleccionar</option>
                                <?php for ($i = 1; $i <= max(1, $installmentsTotal); $i++): ?><option value="<?php echo $i; ?>"><?php echo $i; ?></option><?php endfor; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <p class="form-section-title">Antecedentes de la boleta</p>
                    <div class="field-group field-group-4">
                        <div class="field">
                            <label>N° boleta</label>
                            <input name="boleta_number" required value="<?php echo htmlspecialchars((string) ($selectedReport['boleta_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="field">
                            <label>Fecha de boleta</label>
                            <input type="date" name="boleta_date" required value="<?php echo htmlspecialchars((string) ($selectedReport['boleta_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="field">
                            <label>Valor líquido de la boleta</label>
                            <input type="number" min="1" step="1" name="boleta_amount" required value="<?php echo htmlspecialchars((string) ($selectedReport['boleta_amount'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ej: 805125">
                        </div>
                        <div class="field">
                            <label>Archivo de la boleta (PDF)</label>
                            <?php if ($selectedBoletaFile !== null): ?>
                                <p class="form-footer-note" style="margin:0 0 8px;">Archivo cargado: <?php echo htmlspecialchars((string) $selectedBoletaFile['original_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                            <input type="file" name="boleta_pdf" accept="application/pdf" <?php echo $selectedBoletaFile === null ? 'required' : ''; ?>>
                            <small><?php echo $selectedBoletaFile !== null ? 'Selecciona un PDF solo si deseas reemplazar la boleta.' : 'Debe estar adjunto antes de enviar el informe a firma.'; ?></small>
                        </div>
                    </div>

                    <p class="form-section-title">Funciones y actividades realizadas</p>
                    <?php if (count($selectedFunctions) > 0): ?>
                        <div class="activity-field-stack">
                            <?php foreach ($selectedFunctions as $index => $fn): ?>
                                <div class="field">
                                    <label><?php echo htmlspecialchars((string) $fn['text'], ENT_QUOTES, 'UTF-8'); ?></label>
                                    <textarea class="activity-textarea" name="activity_texts[]" required><?php echo htmlspecialchars((string) (($selectedActivities[$index]['activity_description'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-err">El convenio seleccionado no tiene funciones configuradas.</div>
                    <?php endif; ?>

                    <div class="form-footer" style="align-items:center;">
                        <button class="btn btn-ghost" type="submit" name="wizard_submit" value="save" formnovalidate>
                            Guardar
                        </button>
                        <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;">
                            <a class="btn btn-ghost" href="dashboard.php" title="Volver a la portada">Cerrar</a>
                            <button class="btn" type="submit" name="wizard_submit" value="continue">
                                Continuar
                            </button>
                        </div>
                    </div>
                </form>
                <?php else: ?>
                <div class="wizard-review-panel" style="margin-bottom:18px;">
                    <h3>PDF del informe manual</h3>
                    <?php if ($selectedReportPdf !== null): ?>
                        <div class="uploaded-pdf-row">
                            <div class="uploaded-pdf-info">
                                <span aria-hidden="true">✓</span>
                                <span class="uploaded-pdf-name"><?php echo htmlspecialchars((string) $selectedReportPdf['original_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="uploaded-pdf-actions">
                                <button class="btn btn-ghost btn-sm" type="button" data-preview-pdf="informe_mensual.php?action=view_uploaded_pdf&amp;file_id=<?php echo (int) $selectedReportPdf['id']; ?>">Vista previa</button>
                                <form method="post" action="?report_id=<?php echo (int) $selectedReportId; ?>&amp;step=2#reportWizard" class="upload-inline" data-delete-pdf-form>
                                    <input type="hidden" name="action" value="delete_pdf">
                                    <input type="hidden" name="report_id" value="<?php echo (int) $selectedReportId; ?>">
                                    <input type="hidden" name="file_id" value="<?php echo (int) $selectedReportPdf['id']; ?>">
                                    <button class="btn btn-sm btn-delete-pdf" type="submit">Borrar</button>
                                </form>
                            </div>
                        </div>
                    <?php else: ?>
                        <p>El PDF fue eliminado. Adjunta el informe corregido para poder continuar.</p>
                        <form method="post" action="?report_id=<?php echo (int) $selectedReportId; ?>&amp;step=2#reportWizard" enctype="multipart/form-data" class="upload-inline">
                            <input type="hidden" name="action" value="upload_pdf">
                            <input type="hidden" name="report_id" value="<?php echo (int) $selectedReportId; ?>">
                            <input type="file" name="report_pdf" accept="application/pdf" required>
                            <button class="btn btn-sm" type="submit">Cargar PDF</button>
                        </form>
                    <?php endif; ?>
                </div>
                <form method="post" enctype="multipart/form-data" id="wizardManualBoletaForm">
                    <input type="hidden" name="action" value="save_manual_boleta">
                    <input type="hidden" name="report_id" value="<?php echo (int) $selectedReportId; ?>">

                    <p class="form-section-title">Antecedentes de la boleta</p>
                    <div class="field-group field-group-4">
                        <div class="field">
                            <label>N° boleta</label>
                            <input name="boleta_number" value="<?php echo htmlspecialchars((string) ($selectedReport['boleta_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="field">
                            <label>Fecha de boleta</label>
                            <input type="date" name="boleta_date" value="<?php echo htmlspecialchars((string) ($selectedReport['boleta_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="field">
                            <label>Valor líquido de la boleta</label>
                            <input type="number" min="1" step="1" name="boleta_amount" value="<?php echo htmlspecialchars((string) ($selectedReport['boleta_amount'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ej: 805125">
                        </div>
                        <div class="field">
                            <label>Archivo de la boleta (PDF)</label>
                            <?php if ($selectedBoletaFile !== null): ?>
                                <p class="form-footer-note" style="margin:0 0 8px;">Archivo cargado: <?php echo htmlspecialchars((string) $selectedBoletaFile['original_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                            <input type="file" name="boleta_pdf" accept="application/pdf">
                            <small><?php echo $selectedBoletaFile !== null ? 'Selecciona un PDF solo si deseas reemplazar la boleta.' : 'Debe estar adjunto antes de continuar.'; ?></small>
                        </div>
                    </div>
                    <div class="options-note" style="margin-top:14px;">
                        <p><strong>Informe manual.</strong> El PDF del informe ya quedó guardado en la etapa anterior. En esta etapa solo debes completar la boleta.</p>
                    </div>
                    <div class="form-footer" style="align-items:center;">
                        <button class="btn btn-ghost" type="submit" name="wizard_submit" value="save" formnovalidate>Guardar</button>
                        <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;">
                            <a class="btn btn-ghost" href="dashboard.php" title="Volver a la portada">Cerrar</a>
                            <button class="btn" type="submit" name="wizard_submit" value="continue">Continuar</button>
                        </div>
                    </div>
                </form>
                <?php endif; ?>
                <?php elseif ($wizardStep === 3): ?>
                    <div class="wizard-review-grid">
                        <section class="wizard-review-panel">
                            <h3>Resumen del informe</h3>
                            <div class="wizard-summary" style="grid-template-columns:repeat(2,minmax(0,1fr));margin-bottom:0;">
                                <div class="wizard-summary-item"><span>Periodo</span><strong><?php echo htmlspecialchars(($monthNames[(int) $selectedReport['report_month']] ?? '') . ' de ' . (string) $selectedReport['report_year'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                <div class="wizard-summary-item"><span>Cuota</span><strong><?php echo $selectedIsConvenio ? 'N.º ' . (int) ($selectedReport['installment_number'] ?? 0) : 'No aplica'; ?></strong></div>
                                <div class="wizard-summary-item"><span><?php echo $selectedIsConvenio ? 'Convenio' : 'Modalidad'; ?></span><strong><?php echo htmlspecialchars($selectedAgreementNumber, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                <div class="wizard-summary-item"><span>Dirección</span><strong><?php echo htmlspecialchars((string) $selectedReport['supervision_unit'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                <div class="wizard-summary-item"><span>Boleta</span><strong>N.º <?php echo htmlspecialchars((string) ($selectedReport['boleta_number'] ?? 'Pendiente'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                <div class="wizard-summary-item"><span>Valor líquido</span><strong>$<?php echo number_format((float) ($selectedReport['boleta_amount'] ?? 0), 0, ',', '.'); ?></strong></div>
                            </div>
                        </section>
                        <section class="wizard-review-panel">
                            <h3>Validación</h3>
                            <ul class="wizard-check-list">
                                <?php if ($selectedIsConvenio): ?>
                                    <li><span class="wizard-check-icon<?php echo $selectedActivitiesComplete ? '' : ' is-missing'; ?>"><?php echo $selectedActivitiesComplete ? '✓' : '!'; ?></span><span><?php echo $selectedActivitiesComplete ? 'Todas las actividades están completas.' : 'Faltan actividades por completar.'; ?></span></li>
                                <?php else: ?>
                                    <li><span class="wizard-check-icon<?php echo $selectedReportPdf !== null ? '' : ' is-missing'; ?>"><?php echo $selectedReportPdf !== null ? '✓' : '!'; ?></span><span>Archivo PDF del informe manual.</span></li>
                                <?php endif; ?>
                                <?php $selectedHasBoletaIdentity = trim((string) ($selectedReport['boleta_number'] ?? '')) !== '' && trim((string) ($selectedReport['boleta_date'] ?? '')) !== ''; ?>
                                <li><span class="wizard-check-icon<?php echo $selectedHasBoletaIdentity ? '' : ' is-missing'; ?>"><?php echo $selectedHasBoletaIdentity ? '✓' : '!'; ?></span><span>Número y fecha de la boleta.</span></li>
                                <li><span class="wizard-check-icon<?php echo (float) ($selectedReport['boleta_amount'] ?? 0) > 0 ? '' : ' is-missing'; ?>"><?php echo (float) ($selectedReport['boleta_amount'] ?? 0) > 0 ? '✓' : '!'; ?></span><span>Valor líquido de la boleta.</span></li>
                                <li><span class="wizard-check-icon<?php echo $selectedBoletaFile !== null ? '' : ' is-missing'; ?>"><?php echo $selectedBoletaFile !== null ? '✓' : '!'; ?></span><span>Archivo PDF de la boleta.</span></li>
                            </ul>
                        </section>
                    </div>
                    <div class="form-footer">
                        <div style="display:flex;gap:10px;flex-wrap:wrap;">
                            <a class="btn btn-ghost" href="?report_id=<?php echo (int) $selectedReportId; ?>&amp;step=2#reportWizard">Volver</a>
                            <?php if ($selectedIsConvenio): ?>
                                <button class="btn btn-ghost" type="button" data-preview-pdf="informe_mensual.php?action=preview_pdf&amp;report_id=<?php echo (int) $selectedReportId; ?>">Vista previa del informe</button>
                            <?php elseif ($selectedReportPdf !== null): ?>
                                <button class="btn btn-ghost" type="button" data-preview-pdf="informe_mensual.php?action=view_uploaded_pdf&amp;file_id=<?php echo (int) $selectedReportPdf['id']; ?>">Vista previa del informe</button>
                            <?php endif; ?>
                        </div>
                        <?php if ($selectedReadyForReview): ?>
                            <a class="btn" href="?report_id=<?php echo (int) $selectedReportId; ?>&amp;step=4#reportWizard">Continuar</a>
                        <?php else: ?>
                            <a class="btn" href="?report_id=<?php echo (int) $selectedReportId; ?>&amp;step=2#reportWizard">Corregir información faltante</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php if ($selectedReadyForReview): ?>
                        <div class="wizard-sign-panel">
                            <h3>Informe listo para firmar</h3>
                            <p>Al continuar, recibirás en tu correo un enlace personal para firmar el informe. Después de tu firma se enviará automáticamente al buzón del director correspondiente.</p>
                            <form method="post" data-signature-request-form data-missing-boleta="">
                                <input type="hidden" name="action" value="send_signature_request">
                                <input type="hidden" name="report_id" value="<?php echo (int) $selectedReportId; ?>">
                                <input type="hidden" name="file_id" value="<?php echo $selectedIsConvenio ? 0 : (int) ($selectedReportPdf['id'] ?? 0); ?>">
                                <?php if ($selectedIsConvenio): ?><input type="hidden" name="prepare_convenio_pdf" value="1"><?php endif; ?>
                                <button class="btn" type="submit">Firmar</button>
                            </form>
                        </div>
                        <div class="form-footer"><a class="btn btn-ghost" href="?report_id=<?php echo (int) $selectedReportId; ?>&amp;step=3#reportWizard">Volver a revisión</a></div>
                    <?php else: ?>
                        <div class="alert alert-err">El informe aún tiene información pendiente. Completa las actividades y los antecedentes de la boleta antes de enviarlo a firma.</div>
                        <div class="form-footer"><a class="btn" href="?report_id=<?php echo (int) $selectedReportId; ?>&amp;step=2#reportWizard">Completar información</a></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Listado -->
        <?php if (!$showCreateForm && $selectedReportId <= 0): ?>
        <div class="card" id="reportsListCard">
            <div class="card-header">
                <h2><span class="step">3</span> Informes registrados</h2>
                <span style="font-size:.82rem;color:var(--text-muted);"><?php echo count($reports); ?> informe<?php echo count($reports) !== 1 ? 's' : ''; ?></span>
            </div>
            <div class="card-body" style="padding-bottom: 0;">
                <div class="options-note">
                    <p><strong>Informes de convenio.</strong> Selecciona el informe para completar una actividad por cada funcion del convenio.</p>
                    <p><strong>Informes manuales.</strong> Solo debes adjuntar el PDF del informe final.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Periodo</th>
                            <th>Origen</th>
                            <th>Convenio</th>
                            <th>Estado</th>
                            <th>Actividades</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $r): ?>
                        <?php
                            $reportId = (int) $r['id'];
                            $agreementId = (int) ($r['agreement_id'] ?? 0);
                            $isConvenio = (string) $r['source_type'] === 'CONVENIO';
                            $hasActivities = (int) ($r['activities_count'] ?? 0) > 0;
                            $functionsForAgreement = $agreementFunctionsMap[$agreementId] ?? [];
                            $uploadedReportPdf = $pdfFilesByReport[$reportId] ?? null;
                            $isSignedReportPdf = $uploadedReportPdf !== null && (int) ($uploadedReportPdf['is_signed'] ?? 0) === 1;
                            $isPendingEmployeeSignature = $uploadedReportPdf !== null && (int) ($uploadedReportPdf['is_pending_signature'] ?? 0) === 1;
                            $hasDirectorSignature = in_array((string) ($r['status'] ?? ''), ['APROBADO','APROBADO_PAGO'], true)
                                || trim((string) ($r['director_signed_at'] ?? '')) !== '';
                            $isFinalizedReport = $hasDirectorSignature;
                            $canDeleteReport = (string) ($r['status'] ?? '') === 'BORRADOR'
                                && trim((string) ($r['submitted_at'] ?? '')) === ''
                                && (int) ($r['signature_requests_count'] ?? 0) === 0;
                            $missingBoletaFields = [];
                            if (trim((string) ($r['boleta_number'] ?? '')) === '') $missingBoletaFields[] = 'número de boleta';
                            if (trim((string) ($r['boleta_date'] ?? '')) === '') $missingBoletaFields[] = 'fecha de boleta';
                            if ((float) ($r['boleta_amount'] ?? 0) <= 0) $missingBoletaFields[] = 'valor líquido de la boleta';
                            if ((int) ($r['boleta_file_count'] ?? 0) < 1) $missingBoletaFields[] = 'archivo PDF de la boleta';
                            $missingBoletaMessage = implode(', ', $missingBoletaFields);
                            $canCompleteActivities = (string) ($r['status'] ?? '') === 'RECHAZADO'
                                || (
                                    !$isPendingEmployeeSignature
                                    && !$isSignedReportPdf
                                    && !$hasDirectorSignature
                                    && (string) ($r['status'] ?? '') !== 'ENVIADO'
                                );
                        ?>
                        <tr>
                            <td class="td-period">
                                <?php echo htmlspecialchars($monthNames[(int) $r['report_month']] ?? (string) $r['report_month'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php echo htmlspecialchars((string) $r['report_year'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td>
                                <span class="td-origin <?php echo $isConvenio ? 'origin-convenio' : 'origin-manual'; ?>">
                                    <?php echo $isConvenio ? 'Convenio' : 'Manual'; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars((string) ($agreementMap[$agreementId] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo statusBadge((string) $r['status']); ?></td>
                            <td>
                                <?php if ($isConvenio): ?>
                                    <span class="btn btn-muted btn-sm">
                                        <?php echo (int) ($r['activities_count'] ?? 0); ?> actividad<?php echo (int) ($r['activities_count'] ?? 0) !== 1 ? 'es' : ''; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="btn btn-muted btn-sm">No aplica</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions-cell">
                                <div class="action-stack">
                                    <?php if ((string) $r['status'] === 'RECHAZADO'): ?>
                                    <div class="action-box" style="border-color:#fecaca;background:#fff7f7">
                                        <h4><?php echo trim((string)($r['finance_rejected_at'] ?? '')) !== '' ? 'Informe rechazado por Finanzas' : 'Informe devuelto para corrección'; ?></h4>
                                        <p><strong>Observación:</strong> <?php echo htmlspecialchars((string) (
                                            trim((string)($r['finance_observation'] ?? '')) !== ''
                                                ? $r['finance_observation']
                                                : ($r['director_rejection_observation'] ?? $r['observations'] ?? '')
                                        ), ENT_QUOTES, 'UTF-8'); ?></p>
                                        <form method="post"><input type="hidden" name="action" value="reopen_rejected"><input type="hidden" name="report_id" value="<?php echo $reportId; ?>"><button class="btn btn-sm" type="submit">Corregir y enviar nuevamente</button></form>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($canCompleteActivities): ?>
                                    <a class="btn btn-sm" href="?report_id=<?php echo $reportId; ?>&amp;step=2#reportWizard">Continuar informe</a>
                                    <?php endif; ?>

                                    <?php if ($isConvenio && ($isFinalizedReport || $isSignedReportPdf || $isPendingEmployeeSignature)): ?>
                                    <div class="action-box">
                                        <?php if ($isFinalizedReport && $uploadedReportPdf !== null): ?>
                                        <h4>Proceso de firmas finalizado</h4>
                                        <div class="uploaded-pdf-actions">
                                            <button class="btn btn-ghost btn-sm" type="button" data-preview-pdf="informe_mensual.php?action=view_uploaded_pdf&amp;file_id=<?php echo (int) $uploadedReportPdf['id']; ?>">Ver informe</button>
                                            <button class="btn btn-ghost btn-sm" type="button" data-preview-pdf="informe_mensual.php?action=view_document_pdf&amp;type=CERTIFICATE&amp;report_id=<?php echo $reportId; ?>">Ver certificado</button>
                                            <button class="btn btn-ghost btn-sm" type="button" data-print-bundle="<?php echo $reportId; ?>" title="Unir e imprimir expediente" aria-label="Unir e imprimir informe, boleta, certificado, decreto y convenio">🖨</button>
                                        </div>
                                        <?php elseif ($isSignedReportPdf && $uploadedReportPdf !== null): ?>
                                        <h4>Enviado a Firma (Director(a))</h4>
                                        <div class="uploaded-pdf-actions">
                                            <button class="btn btn-ghost btn-sm" type="button" data-preview-pdf="informe_mensual.php?action=view_uploaded_pdf&amp;file_id=<?php echo (int) $uploadedReportPdf['id']; ?>">Ver informe</button>
                                        </div>
                                        <?php elseif ($isPendingEmployeeSignature && $uploadedReportPdf !== null): ?>
                                        <h4>Enviado a Firma (Funcionario)</h4>
                                        <div class="uploaded-pdf-actions">
                                            <button class="btn btn-ghost btn-sm" type="button" data-preview-pdf="informe_mensual.php?action=view_uploaded_pdf&amp;file_id=<?php echo (int) $uploadedReportPdf['id']; ?>">Ver informe</button>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!$isConvenio && !$canCompleteActivities): ?>
                                    <div class="action-box">
                                        <h4><?php echo $isFinalizedReport ? 'Proceso de firmas finalizado' : ($isSignedReportPdf ? 'Enviado a Firma (Director(a))' : ($isPendingEmployeeSignature ? 'Enviado a Firma (Funcionario)' : 'Adjuntar informe en PDF')); ?></h4>
                                        <?php if (!$isPendingEmployeeSignature && !$isSignedReportPdf && !$isFinalizedReport): ?>
                                            <form method="post" enctype="multipart/form-data" class="manual-boleta-form" style="display:grid;grid-template-columns:1fr 1fr 1fr 1.4fr auto;gap:8px;margin-bottom:12px;align-items:end">
                                                <input type="hidden" name="action" value="save_manual_boleta"><input type="hidden" name="report_id" value="<?php echo $reportId; ?>">
                                                <label>N.º boleta<input name="boleta_number" value="<?php echo htmlspecialchars((string)($r['boleta_number'] ?? ''),ENT_QUOTES,'UTF-8'); ?>"></label>
                                                <label>Fecha<input type="date" name="boleta_date" value="<?php echo htmlspecialchars((string)($r['boleta_date'] ?? ''),ENT_QUOTES,'UTF-8'); ?>"></label>
                                                <label>Valor líquido<input type="number" min="0" step="1" name="boleta_amount" value="<?php echo htmlspecialchars((string)($r['boleta_amount'] ?? ''),ENT_QUOTES,'UTF-8'); ?>"></label>
                                                <label>PDF de boleta<input type="file" name="boleta_pdf" accept="application/pdf"></label>
                                                <button class="btn btn-sm" type="submit">Guardar boleta</button>
                                            </form>
                                        <?php endif; ?>                                        <?php if ($uploadedReportPdf !== null): ?>
                                            <div class="uploaded-pdf-row">
                                                <div class="uploaded-pdf-info" title="<?php echo htmlspecialchars((string) $uploadedReportPdf['original_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <span aria-hidden="true">✓</span>
                                                    <span class="uploaded-pdf-name"><?php echo htmlspecialchars((string) $uploadedReportPdf['original_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                </div>
                                                <div class="uploaded-pdf-actions">
                                                    <?php if ($isSignedReportPdf): ?>
                                                        <button class="btn btn-sm" type="button" data-preview-pdf="informe_mensual.php?action=view_uploaded_pdf&amp;file_id=<?php echo (int) $uploadedReportPdf['id']; ?>">Ver Informe</button>
                                                        <?php if ($isFinalizedReport): ?>
                                                            <button class="btn btn-ghost btn-sm" type="button" data-preview-pdf="informe_mensual.php?action=view_document_pdf&amp;type=CERTIFICATE&amp;report_id=<?php echo $reportId; ?>">Ver certificado</button>
                                                            <button class="btn btn-ghost btn-sm" type="button" data-print-bundle="<?php echo $reportId; ?>" title="Unir e imprimir expediente" aria-label="Unir e imprimir informe, boleta, certificado, decreto y convenio">🖨</button>
                                                        <?php endif; ?>
                                                    <?php elseif ($isPendingEmployeeSignature): ?>
                                                        <button class="btn btn-ghost btn-sm" type="button" data-preview-pdf="informe_mensual.php?action=view_uploaded_pdf&amp;file_id=<?php echo (int) $uploadedReportPdf['id']; ?>">Vista previa</button>
                                                    <?php else: ?>
                                                        <form method="post" class="upload-inline" data-delete-pdf-form>
                                                            <input type="hidden" name="action" value="delete_pdf">
                                                            <input type="hidden" name="report_id" value="<?php echo $reportId; ?>">
                                                            <input type="hidden" name="file_id" value="<?php echo (int) $uploadedReportPdf['id']; ?>">
                                                            <button class="btn btn-sm btn-delete-pdf" type="submit">Borrar</button>
                                                        </form>
                                                        <button class="btn btn-ghost btn-sm" type="button" data-preview-pdf="informe_mensual.php?action=view_uploaded_pdf&amp;file_id=<?php echo (int) $uploadedReportPdf['id']; ?>">Vista previa</button>
                                                        <form method="post" class="upload-inline" data-signature-request-form data-missing-boleta="<?php echo htmlspecialchars($missingBoletaMessage, ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="action" value="send_signature_request">
                                                            <input type="hidden" name="report_id" value="<?php echo $reportId; ?>">
                                                            <input type="hidden" name="file_id" value="<?php echo (int) $uploadedReportPdf['id']; ?>">
                                                            <button class="btn btn-sm" type="submit">Firmar</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <p>Adjunta el archivo PDF del informe final realizado fuera del sistema.</p>
                                            <form method="post" enctype="multipart/form-data" class="upload-inline">
                                                <input type="hidden" name="action" value="upload_pdf">
                                                <input type="hidden" name="report_id" value="<?php echo $reportId; ?>">
                                                <input type="file" name="report_pdf" accept="application/pdf" required>
                                                <button class="btn btn-sm" type="submit">Subir PDF</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($canDeleteReport): ?>
                                    <form method="post" data-delete-report-form>
                                        <input type="hidden" name="action" value="delete_report">
                                        <input type="hidden" name="report_id" value="<?php echo $reportId; ?>">
                                        <button class="btn btn-sm btn-delete-pdf" type="submit">Borrar informe</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($reports) === 0): ?>
                        <tr><td colspan="6" class="td-empty">
                            <svg width="32" height="32" style="display:block;margin:0 auto 10px;opacity:.35" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            No hay informes creados aún. Usa el formulario de arriba para crear el primero.
                        </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const srcConvenio    = document.getElementById('srcConvenio');
        const srcManual      = document.getElementById('srcManual');
        const agreementSelect = document.getElementById('agreementSelect');
        const agreementWrap  = document.getElementById('agreementSelectWrap');
        const professionInput = document.querySelector('input[name="profession_experience"]');
        const supervisionInput = document.getElementById('supervisionInput');
        const programInput   = document.getElementById('programInput');
        const decreeInput    = document.getElementById('decreeInput');
        const startInput     = document.getElementById('startInput');
        const endInput       = document.getElementById('endInput');
        const installmentInput = document.getElementById('installmentInput');
        const createCard = document.getElementById('createReportCard');
        const createChoiceModal = document.getElementById('createChoiceModal');
        const openCreateChoiceBtn = document.getElementById('openCreateChoiceBtn');
        const closeCreateChoiceModal = document.getElementById('closeCreateChoiceModal');
        const choiceCards = document.querySelectorAll('[data-create-source]');
        const openAgreementStepButton = document.querySelector('[data-open-agreement-step]');
        const createTypeStep = document.getElementById('createTypeStep');
        const createAgreementStep = document.getElementById('createAgreementStep');
        const backToCreateTypeButton = document.getElementById('backToCreateTypeBtn');
        const agreementChoiceButtons = document.querySelectorAll('[data-select-agreement]');
        const createChoiceTitle = document.getElementById('createChoiceTitle');
        const createChoiceDescription = createChoiceTitle ? createChoiceTitle.nextElementSibling : null;
        const createWizardProgress = document.getElementById('createWizardProgress');
        const createReportButtonText = document.getElementById('createReportButtonText');
        const manualCreateBoletaFields = document.querySelectorAll('.manual-create-boleta-field');
        const manualPdfPanel = document.getElementById('manualPdfPanel');
        const manualProfileFields = document.getElementById('manualProfileFields');
        const saveProfileRecords = document.getElementById('saveProfileRecords');
        const reportPdfManual = document.getElementById('reportPdfManual');
        const agreementPdfManual = document.getElementById('agreementPdfManual');
        const decreePdfManual = document.getElementById('decreePdfManual');
        const boletaPdfManual = document.getElementById('boletaPdfManual');
        const boletaNumberInput = document.getElementById('boletaNumberInput');
        const boletaDateInput = document.getElementById('boletaDateInput');
        const createSourcePill = document.getElementById('createSourcePill');
        const agreementNumberManual = document.getElementById('agreementNumberManual');
        const agreementDateManual = document.getElementById('agreementDateManual');
        const installmentsTotalManual = document.getElementById('installmentsTotalManual');
        const decreeDateManual = document.getElementById('decreeDateManual');
        const reportsListCard = document.getElementById('reportsListCard');
        const reportActivitiesCard = document.getElementById('reportActivitiesCard');

        function applyAgreementData() {
            const opt = agreementSelect.options[agreementSelect.selectedIndex];
            if (!opt || !opt.value) return;
            if (opt.dataset.profession && professionInput) professionInput.value = opt.dataset.profession;

            if (opt.dataset.program)      programInput.value = opt.dataset.program;
            if (opt.dataset.decree)       decreeInput.value  = opt.dataset.decree;
            if (opt.dataset.start)        startInput.value   = opt.dataset.start;
            if (opt.dataset.end)          endInput.value     = opt.dataset.end;
            if (installmentInput) {
                const installmentsTotal = Number(opt.dataset.installments || 0);
                const nextInstallment = Number(opt.dataset.nextInstallment || 1);
                if (installmentsTotal > 0) installmentInput.max = String(installmentsTotal);
                else installmentInput.removeAttribute('max');
                installmentInput.value = installmentsTotal > 0 && nextInstallment > installmentsTotal ? '' : String(nextInstallment);
            }
        }

        function setCreateSource(source) {
            const isManual = source === 'MANUAL';
            srcManual.checked = isManual;
            srcConvenio.checked = !isManual;
            agreementWrap.style.display = isManual ? 'none' : 'flex';
            agreementSelect.disabled = isManual;
            manualPdfPanel.classList.toggle('is-visible', isManual);
            manualPdfPanel.style.display = isManual ? 'grid' : 'none';
            reportPdfManual.required = isManual;
            if (boletaNumberInput) boletaNumberInput.required = false;
            if (boletaDateInput) boletaDateInput.required = false;
            if (installmentInput) installmentInput.required = !isManual;
            if (!isManual && manualProfileFields) {
                manualProfileFields.style.display = 'none';
            }
            if (createSourcePill) {
                createSourcePill.textContent = isManual ? 'Modalidad: Manual con PDF' : 'Modalidad: Convenio almacenado';
            }
            if (createWizardProgress) createWizardProgress.style.display = 'grid';
            if (createReportButtonText) createReportButtonText.textContent = 'Guardar y continuar';
            manualCreateBoletaFields.forEach(function (field) {
                field.style.display = 'none';
            });
            if (!isManual) {
                applyAgreementData();
            }
            toggleManualProfileFields();
        }

        function toggleManualProfileFields() {
            const isManual = srcManual.checked;
            const saveProfile = !!(saveProfileRecords && saveProfileRecords.checked);
            if (!manualProfileFields) {
                return;
            }

            manualProfileFields.style.display = isManual && saveProfile ? 'grid' : 'none';
            if (agreementPdfManual) agreementPdfManual.required = isManual && saveProfile;
            if (decreePdfManual) decreePdfManual.required = isManual && saveProfile;
            if (boletaPdfManual) boletaPdfManual.required = false;
            if (agreementNumberManual) agreementNumberManual.required = isManual && saveProfile;
            if (agreementDateManual) agreementDateManual.required = isManual && saveProfile;
            if (installmentsTotalManual) installmentsTotalManual.required = isManual && saveProfile;
            if (decreeDateManual) decreeDateManual.required = isManual && saveProfile;
            if (decreeInput) decreeInput.required = isManual && saveProfile;
        }

        function toggleSource() {
            const isManual = srcManual.checked;
            setCreateSource(isManual ? 'MANUAL' : 'CONVENIO');
        }

        srcConvenio.addEventListener('change', toggleSource);
        srcManual.addEventListener('change', toggleSource);
        agreementSelect.addEventListener('change', applyAgreementData);
        if (saveProfileRecords) {
            saveProfileRecords.addEventListener('change', toggleManualProfileFields);
        }
        toggleSource();
        toggleManualProfileFields();
    </script>
<script>
        document.addEventListener('DOMContentLoaded', function () {
            const cancelButton = document.getElementById('cancelCreateReportBtn');
            const confirmRemoveManualRow = function () {
                if (typeof window.Swal === 'undefined') {
                    return Promise.resolve(window.confirm('Se eliminará esta función con sus actividades. ¿Deseas continuar?'));
                }

                return window.Swal.fire({
                    title: '¿Eliminar función?',
                    text: 'Se borrará esta función junto con las actividades asociadas en esta vista.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Sí, borrar',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#b42318',
                    cancelButtonColor: '#086374',
                    reverseButtons: true,
                    focusCancel: true,
                    background: '#ffffff',
                    color: '#1e3a5f'
                }).then(function (result) {
                    return result.isConfirmed;
                });
            };

            document.querySelectorAll('[data-delete-pdf-form]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!window.confirm('¿Deseas borrar el PDF cargado? Podrás seleccionar uno nuevo después.')) {
                        event.preventDefault();
                    }
                });
            });

            document.querySelectorAll('[data-delete-report-form]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    event.preventDefault();

                    if (typeof window.Swal === 'undefined') {
                        if (window.confirm('¿Deseas borrar este informe? Se eliminarán sus actividades, archivos y solicitudes de firma.')) {
                            HTMLFormElement.prototype.submit.call(form);
                        }
                        return;
                    }

                    window.Swal.fire({
                        title: '¿Borrar informe?',
                        text: 'Se eliminarán el informe, sus actividades, archivos y solicitudes de firma. Esta acción no se puede deshacer.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Sí, borrar informe',
                        cancelButtonText: 'Cancelar',
                        confirmButtonColor: '#b42318',
                        cancelButtonColor: '#086374',
                        reverseButtons: true,
                        focusCancel: true,
                        background: '#ffffff',
                        color: '#1e3a5f'
                    }).then(function (result) {
                        if (!result.isConfirmed) return;

                        window.Swal.fire({
                            title: 'Eliminando informe',
                            text: 'Espere un momento.',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            showConfirmButton: false,
                            didOpen: function () {
                                window.Swal.showLoading();
                            }
                        });
                        HTMLFormElement.prototype.submit.call(form);
                    });
                });
            });
            const pdfPreviewModal = document.getElementById('pdfPreviewModal');
            const pdfPreviewFrame = document.getElementById('pdfPreviewFrame');
            const closePdfPreviewModalButton = document.getElementById('closePdfPreviewModal');
            const pdfPreviewFooter = document.getElementById('pdfPreviewFooter');
            const previewSignForm = document.getElementById('previewSignForm');
            const previewSignReportId = document.getElementById('previewSignReportId');

            const closePdfPreview = function () {
                if (!pdfPreviewModal) return;
                pdfPreviewModal.style.display = 'none';
                pdfPreviewModal.setAttribute('aria-hidden', 'true');
                if (pdfPreviewFrame) pdfPreviewFrame.removeAttribute('src');
                if (pdfPreviewFooter) pdfPreviewFooter.classList.remove('is-visible');
                if (previewSignReportId) previewSignReportId.value = '';
                document.body.style.overflow = '';
            };

            document.querySelectorAll('[data-preview-pdf]').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!pdfPreviewModal || !pdfPreviewFrame) return;
                    pdfPreviewFrame.src = button.dataset.previewPdf || '';
                    const signReportId = button.dataset.previewSignReport || '';
                    if (previewSignReportId) previewSignReportId.value = signReportId;
                    if (previewSignForm) previewSignForm.dataset.missingBoleta = button.dataset.missingBoleta || '';
                    if (pdfPreviewFooter) pdfPreviewFooter.classList.toggle('is-visible', signReportId !== '');
                    pdfPreviewModal.style.display = 'flex';
                    pdfPreviewModal.setAttribute('aria-hidden', 'false');
                    document.body.style.overflow = 'hidden';
                });
            });

            const submitSignatureRequest = function (form) {
                const missingBoleta = (form.dataset.missingBoleta || '').trim();
                if (missingBoleta !== '') {
                    const warningText = 'Antes de firmar debes completar: ' + missingBoleta + '. Guarda nuevamente el informe y luego intenta firmar.';
                    if (typeof window.Swal !== 'undefined') {
                        window.Swal.fire({
                            title: 'Faltan antecedentes de la boleta',
                            text: warningText,
                            icon: 'warning',
                            confirmButtonText: 'Entendido',
                            confirmButtonColor: '#086374'
                        });
                    } else {
                        window.alert(warningText);
                    }
                    return;
                }

                const sendRequest = function () {
                    if (typeof window.Swal !== 'undefined') {
                        window.Swal.fire({
                            title: 'Enviando informe a firma',
                            text: 'Espere un momento mientras enviamos el correo.',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            showConfirmButton: false,
                            didOpen: function () {
                                window.Swal.showLoading();
                            }
                        });
                        window.setTimeout(function () {
                            HTMLFormElement.prototype.submit.call(form);
                        }, 150);
                        return;
                    }

                    form.querySelectorAll('button[type="submit"]').forEach(function (button) {
                        button.disabled = true;
                        button.textContent = 'Enviando informe a firma';
                    });
                    HTMLFormElement.prototype.submit.call(form);
                };

                if (typeof window.Swal === 'undefined') {
                    if (window.confirm('¿Está seguro que desea enviar el informe a firma?\n\nLo recibirá en su correo para firmar.')) {
                        sendRequest();
                    }
                    return;
                }

                window.Swal.fire({
                    title: '¿Está seguro que desea enviar el informe a firma?',
                    text: 'Lo recibirá en su correo para firmar.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Firmar',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#086374',
                    cancelButtonColor: '#64748b',
                    reverseButtons: true,
                    focusCancel: true
                }).then(function (result) {
                    if (result.isConfirmed) {
                        sendRequest();
                    }
                });
            };

            document.querySelectorAll('[data-signature-request-form]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    submitSignatureRequest(form);
                });
            });

            document.querySelectorAll('[data-send-signature-report]').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!previewSignForm || !previewSignReportId) return;
                    previewSignReportId.value = button.dataset.sendSignatureReport || '';
                    previewSignForm.dataset.missingBoleta = button.dataset.missingBoleta || '';
                    submitSignatureRequest(previewSignForm);
                });
            });
            let bundleObjectUrl = '';
            document.querySelectorAll('[data-print-bundle]').forEach(function (button) {
                button.addEventListener('click', async function () {
                    const reportId = button.dataset.printBundle || '';
                    if (!reportId || typeof window.PDFLib === 'undefined') return;
                    const documents = [
                        ['REPORT', 'informe'], ['BOLETA', 'boleta'], ['CERTIFICATE', 'certificado'], ['DECREE', 'decreto'], ['AGREEMENT', 'convenio']
                    ];
                    if (typeof window.Swal !== 'undefined') window.Swal.fire({title:'Preparando expediente',text:'Uniendo informe, boleta, certificado, decreto y convenio.',allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:function(){window.Swal.showLoading();}});
                    try {
                        const responses = await Promise.all(documents.map(function (documentInfo) {
                            return fetch('informe_mensual.php?action=view_document_pdf&report_id=' + encodeURIComponent(reportId) + '&type=' + documentInfo[0]);
                        }));
                        const missing = responses.map(function (response,index) { return response.ok ? '' : documents[index][1]; }).filter(Boolean);
                        if (missing.length) throw new Error('No se puede completar el expediente. Faltan: ' + missing.join(', ') + '.');
                        const merged = await PDFLib.PDFDocument.create();
                        for (let index=0; index<responses.length; index++) {
                            const source = await PDFLib.PDFDocument.load(await responses[index].arrayBuffer());
                            const pages = await merged.copyPages(source, source.getPageIndices());
                            pages.forEach(function (page) { merged.addPage(page); });
                        }
                        const mergedBytes = await merged.save();
                        if (bundleObjectUrl) URL.revokeObjectURL(bundleObjectUrl);
                        bundleObjectUrl = URL.createObjectURL(new Blob([mergedBytes], {type:'application/pdf'}));
                        if (typeof window.Swal !== 'undefined') window.Swal.close();
                        if (pdfPreviewModal && pdfPreviewFrame) {
                            pdfPreviewFrame.src = bundleObjectUrl;
                            pdfPreviewModal.style.display = 'flex';
                            pdfPreviewModal.setAttribute('aria-hidden','false');
                            document.body.style.overflow='hidden';
                        } else {
                            window.open(bundleObjectUrl, '_blank', 'noopener');
                        }
                    } catch (bundleError) {
                        const message = bundleError.message || 'No fue posible unir los documentos.';
                        if (typeof window.Swal !== 'undefined') window.Swal.fire({title:'Expediente incompleto',text:message,icon:'warning',confirmButtonText:'Entendido',confirmButtonColor:'#086374'});
                        else window.alert(message);
                    }
                });
            });
            if (closePdfPreviewModalButton) closePdfPreviewModalButton.addEventListener('click', closePdfPreview);
            if (pdfPreviewModal) {
                pdfPreviewModal.addEventListener('click', function (event) {
                    if (event.target === pdfPreviewModal) closePdfPreview();
                });
            }
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && pdfPreviewModal && pdfPreviewModal.getAttribute('aria-hidden') === 'false') closePdfPreview();
            });
            document.querySelectorAll('[data-sign-pdf]').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (typeof window.Swal !== 'undefined') {
                        window.Swal.fire({
                            title: 'Firma electrónica',
                            text: 'La integración para firmar el PDF estará disponible próximamente.',
                            icon: 'info',
                            confirmButtonText: 'Entendido',
                            confirmButtonColor: '#086374'
                        });
                        return;
                    }
                    window.alert('La integración para firmar el PDF estará disponible próximamente.');
                });
            });
            const openModal = function () {
                if (!createChoiceModal) {
                    return;
                }
                showCreateTypeStep();
                createChoiceModal.style.display = 'flex';
                createChoiceModal.setAttribute('aria-hidden', 'false');
                openCreateChoiceBtn.setAttribute('aria-expanded', 'true');
            };

            const closeModal = function () {
                if (!createChoiceModal) {
                    return;
                }
                createChoiceModal.style.display = 'none';
                createChoiceModal.setAttribute('aria-hidden', 'true');
                openCreateChoiceBtn.setAttribute('aria-expanded', 'false');
            };

            const showCreateTypeStep = function () {
                if (createTypeStep) createTypeStep.hidden = false;
                if (createAgreementStep) createAgreementStep.hidden = true;
                if (createChoiceTitle) createChoiceTitle.textContent = '¿Qué tipo de informe deseas crear?';
                if (createChoiceDescription) createChoiceDescription.textContent = 'Elige si el informe se relaciona a un convenio almacenado o si cargarás un PDF ya preparado.';
            };

            const showAgreementStep = function () {
                if (createTypeStep) createTypeStep.hidden = true;
                if (createAgreementStep) createAgreementStep.hidden = false;
                if (createChoiceTitle) createChoiceTitle.textContent = '¿Qué convenio deseas seleccionar?';
                if (createChoiceDescription) createChoiceDescription.textContent = 'Se muestran únicamente tus convenios vigentes.';
            };

            if (!openCreateChoiceBtn || !createChoiceModal || !createCard) {
                return;
            }

            const setVisible = function (visible) {
                createCard.classList.toggle('is-visible', visible);
                createCard.style.display = visible ? 'block' : 'none';
                if (reportsListCard) reportsListCard.style.display = visible ? 'none' : '';
                if (reportActivitiesCard) reportActivitiesCard.style.display = visible ? 'none' : '';
            };

            const chooseSource = function (source) {
                setCreateSource(source);
                setVisible(true);
                closeModal();
                window.location.hash = '#reportForm';
                if (source === 'MANUAL' && reportPdfManual) {
                    setTimeout(function () {
                        reportPdfManual.focus();
                    }, 50);
                }
            };

            if (createCard.classList.contains('is-visible')) {
                openCreateChoiceBtn.setAttribute('aria-expanded', 'false');
            }

            openCreateChoiceBtn.addEventListener('click', function (event) {
                event.preventDefault();
                openModal();
            });

            if (cancelButton) {
                cancelButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    setVisible(false);
                });
            }

            if (closeCreateChoiceModal) {
                closeCreateChoiceModal.addEventListener('click', closeModal);
            }

            choiceCards.forEach(function (card) {
                card.addEventListener('click', function () {
                    chooseSource(card.dataset.createSource || 'CONVENIO');
                });
            });

            if (openAgreementStepButton) {
                openAgreementStepButton.addEventListener('click', showAgreementStep);
            }

            if (backToCreateTypeButton) {
                backToCreateTypeButton.addEventListener('click', showCreateTypeStep);
            }

            agreementChoiceButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    const agreementId = button.dataset.selectAgreement || '';
                    if (agreementSelect) {
                        agreementSelect.value = agreementId;
                        applyAgreementData();
                    }
                    chooseSource('CONVENIO');
                });
            });

            if (createChoiceModal) {
                createChoiceModal.addEventListener('click', function (event) {
                    if (event.target === createChoiceModal) {
                        closeModal();
                    }
                });
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeModal();
                }
            });

            if (openCreateChoiceBtn) {
                openCreateChoiceBtn.setAttribute('aria-expanded', 'false');
            }

            const manualRowsWrap = document.getElementById('manualFunctionsActivitiesRows');
            const addManualRowButton = document.getElementById('addManualFunctionActivityRow');

            const updateManualLabels = function () {
                if (!manualRowsWrap) {
                    return;
                }
                const rows = manualRowsWrap.querySelectorAll('[data-manual-row]');
                rows.forEach(function (row, idx) {
                    const labels = row.querySelectorAll('label');
                    if (labels.length > 0) {
                        labels[0].textContent = 'Función ' + (idx + 1);
                    }
                    if (labels.length > 1) {
                        labels[1].textContent = 'Actividad de la función ' + (idx + 1);
                    }
                });
            };

            const bindManualRemoveButtons = function () {
                if (!manualRowsWrap) {
                    return;
                }
                manualRowsWrap.querySelectorAll('[data-remove-manual-row]').forEach(function (button) {
                    button.onclick = async function () {
                        const rows = manualRowsWrap.querySelectorAll('[data-manual-row]');
                        if (rows.length <= 1) {
                            return;
                        }
                        const confirmed = await confirmRemoveManualRow();
                        if (!confirmed) {
                            return;
                        }
                        const row = button.closest('[data-manual-row]');
                        if (row) {
                            row.remove();
                            updateManualLabels();
                        }
                    };
                });
            };

            if (manualRowsWrap && addManualRowButton) {
                addManualRowButton.addEventListener('click', function () {
                    const current = manualRowsWrap.querySelectorAll('[data-manual-row]').length;
                    const next = current + 1;
                    const wrapper = document.createElement('div');
                    wrapper.className = 'field';
                    wrapper.setAttribute('data-manual-row', '1');
                    wrapper.innerHTML =
                        '<div class="manual-row-header">' +
                        '<div class="field">' +
                        '<label>Función ' + next + '</label>' +
                        '<input name="manual_function_titles[]" placeholder="Ej: Función de coordinación territorial">' +
                        '</div>' +
                        '<button class="manual-row-remove" type="button" data-remove-manual-row title="Borrar esta función y sus actividades" aria-label="Borrar esta función y sus actividades">×</button>' +
                        '</div>' +
                        '<label style="margin-top:8px;">Actividad de la función ' + next + '</label>' +
                        '<textarea class="activity-textarea" name="manual_activity_texts[]" placeholder="Describe las actividades realizadas para esta función..."></textarea>';
                    manualRowsWrap.appendChild(wrapper);
                    bindManualRemoveButtons();
                    updateManualLabels();
                });
                bindManualRemoveButtons();
                updateManualLabels();
            }
        });
    </script>
    </body>
</html>









