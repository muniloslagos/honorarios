<?php
declare(strict_types=1);

require_once __DIR__ . '/src/auth.php';

$user = requireRole(ROLE_HONORARIO);
$name = htmlspecialchars((string) ($user['name'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$run = htmlspecialchars((string) ($user['run'] ?? '-'), ENT_QUOTES, 'UTF-8');
$loggedAt = htmlspecialchars((string) ($user['logged_at'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Honorario</title>
    <style>
        :root {
            --bg: #f3f7fb;
            --card: #ffffff;
            --text: #17324a;
            --muted: #4e657b;
            --ok: #157347;
            --accent: #0b7285;
        }
        $loggedAtRaw = (string) ($user['logged_at'] ?? '');

        $monthNames = [
            1 => 'enero',
            2 => 'febrero',
            3 => 'marzo',
            4 => 'abril',
            5 => 'mayo',
            6 => 'junio',
            7 => 'julio',
            8 => 'agosto',
            9 => 'septiembre',
            10 => 'octubre',
            11 => 'noviembre',
            12 => 'diciembre',
        ];

        $monthNumber = (int) date('n');
        $currentMonthLabel = ucfirst($monthNames[$monthNumber] ?? 'mes') . ' ' . date('Y');

        $activeConvenios = [
            [
                'code' => 'CONV-2026-014',
                'start' => '2026-01-01',
                'end' => '2026-12-31',
                'signature' => 'Firmado',
            ],
            [
                'code' => 'CONV-2026-055',
                'start' => '2026-07-01',
                'end' => '2026-09-30',
                'signature' => 'Pendiente de firma',
            ],
        ];

        $informeStatus = 'OBSERVADO';
        $informeDueDate = '2026-07-28';
        $lastObservation = [
            'date' => '2026-07-20',
            'by' => 'RRHH',
            'message' => 'Falta detallar actividades del segundo bloque semanal.',
        ];

        function formatDateEs(string $date): string
        {
            $timestamp = strtotime($date);
            if ($timestamp === false) {
                return $date;
            }

            return date('d-m-Y', $timestamp);
        }

        function daysUntil(string $date): int
        {
            $target = new DateTimeImmutable($date);
            $today = new DateTimeImmutable(date('Y-m-d'));

            return (int) $today->diff($target)->format('%r%a');
        }

        function statusLabel(string $status): string
        {
            return match ($status) {
                'NO_INICIADO' => 'No iniciado',
                'BORRADOR' => 'Borrador',
                'ENVIADO' => 'Enviado',
                'OBSERVADO' => 'Observado',
                'APROBADO' => 'Aprobado',
                'RECHAZADO' => 'Rechazado',
                default => 'Sin estado',
            };
        }

        function statusClass(string $status): string
        {
            return match ($status) {
                'NO_INICIADO' => 'neutral',
                'BORRADOR' => 'info',
                'ENVIADO' => 'info',
                'OBSERVADO' => 'warn',
                'APROBADO' => 'ok',
                'RECHAZADO' => 'danger',
                default => 'neutral',
            };
        }

        function resolveMainAction(string $status): array
        {
            return match ($status) {
                'NO_INICIADO' => ['Crear informe de este mes', '#'],
                'BORRADOR' => ['Continuar borrador', '#'],
                'ENVIADO' => ['Ver envio realizado', '#'],
                'OBSERVADO' => ['Corregir informe observado', '#'],
                'APROBADO' => ['Revisar historial del mes', '#'],
                'RECHAZADO' => ['Crear nuevo informe', '#'],
                default => ['Ir a informes', '#'],
            };
        }

        $activeCount = count($activeConvenios);
        $daysRemaining = daysUntil($informeDueDate);
        $daysLabel = $daysRemaining > 0
            ? 'Faltan ' . $daysRemaining . ' dias'
            : ($daysRemaining === 0 ? 'Vence hoy' : 'Vencido hace ' . abs($daysRemaining) . ' dias');

        [$mainActionLabel, $mainActionLink] = resolveMainAction($informeStatus);

        $loggedAtLabel = $loggedAtRaw !== '' ? date('d-m-Y H:i', strtotime($loggedAtRaw) ?: time()) : '-';

        $observationExcerpt = htmlspecialchars($lastObservation['message'], ENT_QUOTES, 'UTF-8');
        $observationBy = htmlspecialchars($lastObservation['by'], ENT_QUOTES, 'UTF-8');
        $observationDate = htmlspecialchars(formatDateEs($lastObservation['date']), ENT_QUOTES, 'UTF-8');
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: linear-gradient(180deg, #e8f4f2 0%, var(--bg) 40%);
            color: var(--text);
        }
            <title>Dashboard Honorario</title>
            padding: 22px 20px;
            background: white;
                    --bg-top: #e9f3f4;
                    --bg-bottom: #f6f9fd;
                    --surface: #ffffff;
                    --surface-soft: #f6f9fd;
                    --text-main: #13324a;
                    --text-muted: #5b7287;
                    --line: #deebf5;
                    --brand: #0f7a8a;
                    --brand-dark: #0c5965;
                    --ok: #1e8f57;
                    --warn: #b86b00;
                    --danger: #b42318;
        }

        .title { font-size: 1.25rem; margin: 0; }

        .logout {
            text-decoration: none;
                    font-family: "Segoe UI", Tahoma, sans-serif;
                    color: var(--text-main);
                    background: linear-gradient(180deg, var(--bg-top), var(--bg-bottom) 45%);
            padding: 8px 12px;

                .layout {
            width: min(1100px, 100%);
                    min-height: 100vh;
            background: var(--card);

                .sidebar {
                    width: 280px;
                    padding: 26px 20px;
                    background: rgba(255, 255, 255, 0.76);
                    border-right: 1px solid var(--line);
                    backdrop-filter: blur(4px);
                }

                .brand {
                    margin: 0 0 18px;
                    font-size: 1.1rem;
                }

                .subtitle {
                    margin: 0 0 22px;
                    color: var(--text-muted);
                    font-size: 0.92rem;
                }

                .menu {
                    display: grid;
                    gap: 8px;
                }

                .menu-item {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    border: 1px solid var(--line);
                    border-radius: 12px;
                    padding: 11px 12px;
                    color: var(--text-main);
            box-shadow: 0 16px 40px rgba(23, 50, 74, 0.09);
                    background: #fff;
                    font-weight: 600;
                    font-size: 0.95rem;
                }

                .menu-item.active {
                    background: linear-gradient(120deg, #ecf8fa, #f8fbff);
                    border-color: #cae6ee;
                    color: var(--brand-dark);
                }

                .menu-tag {
                    font-size: 0.75rem;
                    color: var(--text-muted);
                    border: 1px solid var(--line);
                    border-radius: 999px;
                    padding: 2px 7px;
                }

                .content {
                    flex: 1;
                    padding: 22px;
                }

                .topbar {
                    background: var(--surface);
                    border: 1px solid var(--line);
                    border-radius: 16px;
                    padding: 16px 18px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 14px;
                    flex-wrap: wrap;
                    margin-bottom: 16px;
                }

                .topbar h1 {
                    margin: 0;
                    font-size: clamp(1.2rem, 2.1vw, 1.5rem);
                }

                .topbar p {
                    margin: 3px 0 0;
                    color: var(--text-muted);
                    font-size: 0.93rem;
                }

                .btn {
                    border: 0;
                    border-radius: 10px;
                    padding: 10px 14px;
                    text-decoration: none;
                    font-weight: 700;
                    font-size: 0.9rem;
                    cursor: pointer;
                    display: inline-block;
                }

                .btn-ghost {
                    background: #eef5fb;
                    border: 1px solid #d5e5f4;
                    color: #26537f;
                }

                .btn-primary {
                    background: linear-gradient(135deg, var(--brand), #1493a2);
                    color: #fff;
                }

                .hero {
                    background: linear-gradient(130deg, #0f7a8a 0%, #2f9da8 100%);
                    color: #fff;
                    border-radius: 18px;
                    padding: 24px;
                    margin-bottom: 16px;
                    box-shadow: 0 16px 40px rgba(15, 122, 138, 0.22);
                }

                .hero-badge {
                    display: inline-block;
                    border: 1px solid rgba(255, 255, 255, 0.46);
                    border-radius: 999px;
                    padding: 4px 11px;
                    font-size: 0.82rem;
                    margin-bottom: 10px;
                }

                .hero h2 {
                    margin: 0;
                    font-size: clamp(1.3rem, 2.4vw, 2rem);
                }

                .hero p {
                    margin: 8px 0 0;
                    color: rgba(255, 255, 255, 0.88);
                }

                .hero-actions {
                    margin-top: 16px;
                    display: flex;
                    gap: 10px;
                    flex-wrap: wrap;
                }

                .hero .btn-primary {
                    background: #ffffff;
                    color: #0f6b78;
                }

                .hero .btn-ghost {
                    background: rgba(255, 255, 255, 0.16);
                    border-color: rgba(255, 255, 255, 0.4);
                    color: #fff;
                }

                .grid-kpi {
                    display: grid;
                    grid-template-columns: repeat(4, minmax(170px, 1fr));
                    gap: 12px;
                    margin-bottom: 16px;
                }

                .kpi {
                    background: var(--surface);
                    border: 1px solid var(--line);
                    border-radius: 14px;
                    padding: 14px;
                }

                .kpi-title {
                    margin: 0 0 6px;
                    color: var(--text-muted);
                    font-size: 0.85rem;
                }

                .kpi-value {
                    margin: 0;
                    font-size: 1.35rem;
                    font-weight: 800;
                }

                .kpi-sub {
                    margin: 6px 0 0;
                    color: var(--text-muted);
                    font-size: 0.85rem;
                }

                .status-pill {
                    display: inline-flex;
                    align-items: center;
                    padding: 4px 9px;
                    border-radius: 999px;
                    font-weight: 700;
                    font-size: 0.78rem;
                    border: 1px solid transparent;
                }

                .status-pill.ok { color: var(--ok); background: #e8f8ef; border-color: #c8e9d7; }
                .status-pill.warn { color: var(--warn); background: #fff5e8; border-color: #ffdcb4; }
                .status-pill.info { color: #0f5f95; background: #edf6ff; border-color: #c9e3fb; }
                .status-pill.danger { color: var(--danger); background: #fff0ef; border-color: #ffd4d0; }
                .status-pill.neutral { color: #5a6e80; background: #f1f5f9; border-color: #dde6ee; }

                .grid-main {
                    display: grid;
                    grid-template-columns: 1.3fr 1fr;
                    gap: 14px;
                }

                .panel {
                    background: var(--surface);
                    border: 1px solid var(--line);
                    border-radius: 14px;
                    padding: 16px;
                }

                .panel h3 {
                    margin: 0 0 10px;
                    font-size: 1rem;
                }

                .panel-head {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 10px;
                    margin-bottom: 10px;
                }

                .panel-head a {
                    color: var(--brand-dark);
                    text-decoration: none;
                    font-weight: 700;
                    font-size: 0.88rem;
                }

                .convenio-list {
                    display: grid;
                    gap: 10px;
                }

                .convenio-item {
                    border: 1px solid var(--line);
                    border-radius: 12px;
                    padding: 11px;
                    background: var(--surface-soft);
                }

                .convenio-item h4 {
                    margin: 0 0 7px;
                    font-size: 0.95rem;
                }

                .convenio-meta {
                    margin: 0;
                    color: var(--text-muted);
                    font-size: 0.86rem;
                }

                .side-stack {
                    display: grid;
                    gap: 12px;
                }

                .observation {
                    border-left: 4px solid #f6ad55;
                    background: #fffaf2;
                    border-radius: 10px;
                    padding: 10px 12px;
                    color: #6f4a1e;
                }

                .observation p {
                    margin: 6px 0 0;
                    color: #7f5a2e;
                    line-height: 1.45;
                }

                .timeline {
                    display: grid;
                    gap: 10px;
                }

                .timeline-item {
                    border: 1px solid var(--line);
                    border-radius: 10px;
                    padding: 10px 11px;
                    background: var(--surface-soft);
                }

                .timeline-item strong {
                    display: block;
                    font-size: 0.9rem;
                    margin-bottom: 3px;
                }

                .timeline-item span {
                    color: var(--text-muted);
                    font-size: 0.85rem;
                }

                @media (max-width: 1120px) {
                    .grid-kpi { grid-template-columns: repeat(2, minmax(170px, 1fr)); }
                    .grid-main { grid-template-columns: 1fr; }
                    .sidebar { width: 240px; }
                }

                @media (max-width: 860px) {
                    .layout { display: block; }
                    .sidebar {
                        width: 100%;
                        border-right: 0;
                        border-bottom: 1px solid var(--line);
                    }
                    .content { padding: 14px; }
                }

                @media (max-width: 560px) {
                    .grid-kpi { grid-template-columns: 1fr; }
                    .hero { padding: 18px; }
                }
        
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 0.86rem;
            <div class="layout">
                <aside class="sidebar">
                    <h2 class="brand">Personal a Honorarios</h2>
                    <p class="subtitle">Panel del perfil Honorario</p>

                    <nav class="menu" aria-label="Menu principal">
                        <a class="menu-item active" href="dashboard.php">
                            Inicio
                            <span class="menu-tag">Home</span>
                        </a>
                        <a class="menu-item" href="#">
                            Mis convenios
                            <span class="menu-tag">Prox.</span>
                        </a>
                        <a class="menu-item" href="#">
                            Mis decretos
                            <span class="menu-tag">Prox.</span>
                        </a>
                        <a class="menu-item" href="#">
                            Informe mensual
                            <span class="menu-tag">Prioridad</span>
                        </a>
                        <a class="menu-item" href="#">
                            Carga PDF firmado
                            <span class="menu-tag">Prox.</span>
                        </a>
                        <a class="menu-item" href="#">
                            Historial
                            <span class="menu-tag">Prox.</span>
                        </a>
                    </nav>
                </aside>

                <main class="content">
                    <header class="topbar">
                        <div>
                            <h1>Dashboard Ejecutivo Honorario</h1>
                            <p>RUN <?php echo $run; ?> | Ultimo acceso: <?php echo htmlspecialchars($loggedAtLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                        <a class="btn btn-ghost" href="logout.php">Cerrar sesion</a>
                    </header>

                    <section class="hero">
                        <span class="hero-badge">Perfil activo: HONORARIO</span>
                        <h2>Hola, <?php echo $name; ?></h2>
                        <p>Tu estado operativo de <?php echo htmlspecialchars($currentMonthLabel, ENT_QUOTES, 'UTF-8'); ?> ya esta disponible.</p>
                        <div class="hero-actions">
                            <a class="btn btn-primary" href="<?php echo htmlspecialchars($mainActionLink, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($mainActionLabel, ENT_QUOTES, 'UTF-8'); ?></a>
                            <a class="btn btn-ghost" href="#">Ver historial mensual</a>
                        </div>
                    </section>

                    <section class="grid-kpi" aria-label="Metricas principales">
                        <article class="kpi">
                            <p class="kpi-title">Estado de convenio</p>
                            <p class="kpi-value"><?php echo $activeCount; ?> vigente<?php echo $activeCount > 1 ? 's' : ''; ?></p>
                            <p class="kpi-sub">Revisa vencimientos y estado de firma.</p>
                        </article>

                        <article class="kpi">
                            <p class="kpi-title">Estado informe del mes</p>
                            <p class="kpi-value"><span class="status-pill <?php echo statusClass($informeStatus); ?>"><?php echo statusLabel($informeStatus); ?></span></p>
                            <p class="kpi-sub">Periodo: <?php echo htmlspecialchars($currentMonthLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                        </article>

                        <article class="kpi">
                            <p class="kpi-title">Proxima fecha limite</p>
                            <p class="kpi-value"><?php echo htmlspecialchars(formatDateEs($informeDueDate), ENT_QUOTES, 'UTF-8'); ?></p>
                            <p class="kpi-sub"><?php echo htmlspecialchars($daysLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                        </article>

                        <article class="kpi">
                            <p class="kpi-title">Ultima observacion</p>
                            <p class="kpi-value" style="font-size:1rem;"><?php echo $observationBy; ?></p>
                            <p class="kpi-sub"><?php echo $observationDate; ?></p>
                        </article>
                    </section>

                    <section class="grid-main">
                        <article class="panel">
                            <div class="panel-head">
                                <h3>Convenios vigentes</h3>
                                <a href="#">Ver todos</a>
                            </div>

                            <div class="convenio-list">
                                <?php foreach ($activeConvenios as $convenio): ?>
                                    <div class="convenio-item">
                                        <h4><?php echo htmlspecialchars($convenio['code'], ENT_QUOTES, 'UTF-8'); ?></h4>
                                        <p class="convenio-meta">Inicio: <?php echo htmlspecialchars(formatDateEs($convenio['start']), ENT_QUOTES, 'UTF-8'); ?> | Termino: <?php echo htmlspecialchars(formatDateEs($convenio['end']), ENT_QUOTES, 'UTF-8'); ?></p>
                                        <p class="convenio-meta">Firma: <?php echo htmlspecialchars($convenio['signature'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </article>

                        <div class="side-stack">
                            <article class="panel">
                                <h3>Ultima observacion recibida</h3>
                                <div class="observation">
                                    <strong><?php echo $observationBy; ?> | <?php echo $observationDate; ?></strong>
                                    <p><?php echo $observationExcerpt; ?></p>
                                </div>
                                <p style="margin:10px 0 0;"><a href="#" style="color:#0f5f95;text-decoration:none;font-weight:700;">Ver detalle y corregir</a></p>
                            </article>

                            <article class="panel">
                                <h3>Proximos hitos</h3>
                                <div class="timeline">
                                    <div class="timeline-item">
                                        <strong>Entrega informe mensual</strong>
                                        <span>Fecha limite: <?php echo htmlspecialchars(formatDateEs($informeDueDate), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="timeline-item">
                                        <strong>Carga de convenio firmado</strong>
                                        <span>Fecha sugerida: <?php echo htmlspecialchars(formatDateEs('2026-07-30'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="timeline-item">
                                        <strong>Revision administrativa</strong>
                                        <span>Estado: en seguimiento</span>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </section>
                </main>
            </div>
    <main class="container">
        <section class="hero">
            <span class="badge">Perfil activo: HONORARIO</span>
            <h2>Bienvenido, <?php echo $name; ?></h2>
            <p class="meta">RUN: <?php echo $run; ?> | Ingreso: <?php echo $loggedAt; ?></p>
        </section>

        <section class="cards">
            <article class="card">
                <h3>Mis convenios</h3>
                <p>Revisa el historial de convenios cargados y su estado de firma.</p>
                <a href="#">Ver convenios</a>
            </article>
            <article class="card">
                <h3>Mis decretos</h3>
                <p>Consulta decretos asociados a tus periodos de trabajo.</p>
                <a href="#">Ver decretos</a>
            </article>
            <article class="card">
                <h3>Crear informe</h3>
                <p>Acceso al editor de informes mensuales para su envio.</p>
                <a href="#">Crear informe</a>
            </article>
            <article class="card">
                <h3>Cargar PDF firmado</h3>
                <p>Sube tu convenio firmado para validacion administrativa.</p>
                <a href="#">Subir PDF</a>
            </article>
        </section>
    </main>
</body>
</html>
