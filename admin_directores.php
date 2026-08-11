<?php
declare(strict_types=1);

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/admin_navigation.php';

header('Content-Type: text/html; charset=UTF-8');

$auth = requireRole(ROLE_ADMIN);
$pdo = db();
$success = '';
$error = '';

function validRun(string $run): bool
{
    $clean = strtoupper(preg_replace('/[^0-9K]/i', '', $run) ?? '');
    if (strlen($clean) < 2) return false;
    $body = substr($clean, 0, -1);
    $dv = substr($clean, -1);
    $sum = 0;
    $factor = 2;
    for ($i = strlen($body) - 1; $i >= 0; $i--) {
        $sum += ((int) $body[$i]) * $factor;
        $factor = $factor === 7 ? 2 : $factor + 1;
    }
    $expected = 11 - ($sum % 11);
    $expectedDv = $expected === 11 ? '0' : ($expected === 10 ? 'K' : (string) $expected);
    return $dv === $expectedDv;
}

function formattedRun(string $run): string
{
    $clean = strtoupper(preg_replace('/[^0-9K]/i', '', $run) ?? '');
    return strlen($clean) >= 2 ? substr($clean, 0, -1) . '-' . substr($clean, -1) : $run;
}

function saveDirectorSignature(array $file, int $directorId): ?array
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ((int) ($file['error'] ?? -1) !== UPLOAD_ERR_OK) throw new RuntimeException('No fue posible cargar la firma.');
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('La firma no puede superar 5 MB.');
    $tmp = (string) ($file['tmp_name'] ?? '');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
    if (!isset($extensions[$mime])) throw new RuntimeException('La firma debe ser PNG o JPG.');
    $dimensions = getimagesize($tmp);
    if ($dimensions === false || (int) $dimensions[1] < 1) throw new RuntimeException('No fue posible leer las dimensiones de la imagen.');
    $ratio = (int) $dimensions[0] / (int) $dimensions[1];
    $expectedRatio = 885 / 293;
    if (abs($ratio - $expectedRatio) > 0.08) {
        throw new RuntimeException('La imagen debe medir 885 x 293 px o mantener esa proporción.');
    }
    $dir = __DIR__ . '/uploads/director_signatures';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('No fue posible crear la carpeta de firmas.');
    $name = 'director_' . $directorId . '_' . date('YmdHis') . '.' . $extensions[$mime];
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) throw new RuntimeException('No fue posible guardar la firma.');
    return ['name' => (string) ($file['name'] ?? $name), 'path' => 'uploads/director_signatures/' . $name, 'mime' => $mime];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_direction') {
            $id = (int) ($_POST['direction_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $active = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '' || $code === '') throw new RuntimeException('Nombre y código de dirección son obligatorios.');
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE directions SET name=:name, code=:code, is_active=:active WHERE id=:id');
                $stmt->execute(compact('name', 'code', 'active', 'id'));
            } else {
                $stmt = $pdo->prepare('INSERT INTO directions (name, code, is_active) VALUES (:name,:code,:active)');
                $stmt->execute(compact('name', 'code', 'active'));
            }
            $success = 'Dirección guardada correctamente.';
        } elseif ($action === 'delete_direction') {
            $id = (int) ($_POST['direction_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM directions WHERE id=:id');
            $stmt->execute(['id' => $id]);
            $success = 'Dirección eliminada.';
        } elseif ($action === 'save_director') {
            $profileId = (int) ($_POST['profile_id'] ?? 0);
            $firstNames = trim((string) ($_POST['first_names'] ?? ''));
            $lastNames = trim((string) ($_POST['last_names'] ?? ''));
            $run = formattedRun((string) ($_POST['run'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $position = trim((string) ($_POST['official_position'] ?? ''));
            $username = strtolower(trim((string) ($_POST['local_username'] ?? '')));
            $password = (string) ($_POST['local_password'] ?? '');
            $active = isset($_POST['is_active']) ? 1 : 0;
            $principalId = (int) ($_POST['principal_direction_id'] ?? 0);
            $subrogationIds = array_values(array_unique(array_map('intval', (array) ($_POST['subrogation_direction_ids'] ?? []))));
            $decrees = (array) ($_POST['subrogation_decrees'] ?? []);
            if ($firstNames === '' || $lastNames === '' || !validRun($run) || $position === '' || $principalId < 1) throw new RuntimeException('Completa los campos obligatorios y verifica el RUN.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido.');
            if ($username === '') throw new RuntimeException('El usuario local es obligatorio.');
            if ($profileId === 0 && strlen($password) < 8) throw new RuntimeException('La clave local debe tener al menos 8 caracteres.');

            $pdo->beginTransaction();
            if ($profileId > 0) {
                $find = $pdo->prepare('SELECT system_user_id, signature_path FROM director_profiles WHERE id=:id FOR UPDATE');
                $find->execute(['id' => $profileId]);
                $existing = $find->fetch();
                if ($existing === false) throw new RuntimeException('Director no encontrado.');
                $userId = (int) $existing['system_user_id'];
                $pdo->prepare("UPDATE system_users SET run=:run, first_names=:first, last_names=:last, full_name=:full, email=:email, role='DIRECTOR', is_active=:active WHERE id=:id")
                    ->execute(['run'=>$run,'first'=>$firstNames,'last'=>$lastNames,'full'=>$firstNames.' '.$lastNames,'email'=>$email,'active'=>$active,'id'=>$userId]);
                $sql = 'UPDATE director_profiles SET official_position=:position, local_username=:username, is_active=:active';
                $params = ['position'=>$position,'username'=>$username,'active'=>$active,'id'=>$profileId];
                if ($password !== '') {
                    if (strlen($password) < 8) throw new RuntimeException('La clave local debe tener al menos 8 caracteres.');
                    $sql .= ', local_password_hash=:password';
                    $params['password'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $sql .= ' WHERE id=:id';
                $pdo->prepare($sql)->execute($params);
            } else {
                $pdo->prepare("INSERT INTO system_users (run,first_names,last_names,full_name,email,role,is_active) VALUES (:run,:first,:last,:full,:email,'DIRECTOR',:active)")
                    ->execute(['run'=>$run,'first'=>$firstNames,'last'=>$lastNames,'full'=>$firstNames.' '.$lastNames,'email'=>$email,'active'=>$active]);
                $userId = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO director_profiles (system_user_id,official_position,local_username,local_password_hash,is_active) VALUES (:uid,:position,:username,:password,:active)')
                    ->execute(['uid'=>$userId,'position'=>$position,'username'=>$username,'password'=>password_hash($password, PASSWORD_DEFAULT),'active'=>$active]);
                $profileId = (int) $pdo->lastInsertId();
                $existing = ['signature_path' => null];
            }
            $signature = saveDirectorSignature($_FILES['signature_image'] ?? [], $profileId);
            if ($signature !== null) {
                $pdo->prepare('UPDATE director_profiles SET signature_original_name=:name, signature_path=:path, signature_mime_type=:mime WHERE id=:id')
                    ->execute(['name'=>$signature['name'],'path'=>$signature['path'],'mime'=>$signature['mime'],'id'=>$profileId]);
            }
            $pdo->prepare('DELETE FROM director_directions WHERE director_profile_id=:id')->execute(['id'=>$profileId]);
            $assign = $pdo->prepare('INSERT INTO director_directions (director_profile_id,direction_id,assignment_type,administrative_order,decree_reference) VALUES (:profile,:direction,:type,:ordering,:decree)');
            $assign->execute(['profile'=>$profileId,'direction'=>$principalId,'type'=>'PRINCIPAL','ordering'=>0,'decree'=>null]);
            $order = 1;
            foreach ($subrogationIds as $directionId) {
                if ($directionId === $principalId) continue;
                $assign->execute(['profile'=>$profileId,'direction'=>$directionId,'type'=>'SUBROGANTE','ordering'=>$order++,'decree'=>trim((string) ($decrees[$directionId] ?? '')) ?: null]);
            }
            $pdo->commit();
            $success = 'Director guardado correctamente.';
        } elseif ($action === 'delete_director') {
            $profileId = (int) ($_POST['profile_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT system_user_id FROM director_profiles WHERE id=:id');
            $stmt->execute(['id'=>$profileId]);
            $userId = (int) $stmt->fetchColumn();
            if ($userId < 1) throw new RuntimeException('Director no encontrado.');
            $pdo->prepare('DELETE FROM system_users WHERE id=:id')->execute(['id'=>$userId]);
            $success = 'Director eliminado.';
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e instanceof PDOException && (string) $e->getCode() === '23000'
        ? 'No fue posible guardar: el RUN, usuario, código o asignación ya existe, o el registro está siendo utilizado.'
        : $e->getMessage();
}

$directions = $pdo->query('SELECT * FROM directions ORDER BY name')->fetchAll();
$directors = $pdo->query("SELECT dp.*,u.run,u.first_names,u.last_names,u.email,u.is_active AS user_active FROM director_profiles dp INNER JOIN system_users u ON u.id=dp.system_user_id ORDER BY u.full_name")->fetchAll();
$assignments = [];
foreach ($pdo->query('SELECT * FROM director_directions ORDER BY administrative_order,id')->fetchAll() as $row) $assignments[(int)$row['director_profile_id']][]=$row;
$editId = (int) ($_GET['edit'] ?? 0);
$showForm = ($success === '') && (isset($_GET['new']) || $editId > 0 || $error !== '');
$editing = ['id'=>0,'run'=>'','first_names'=>'','last_names'=>'','email'=>'','official_position'=>'','local_username'=>'','is_active'=>1,'signature_path'=>''];
if ($editId > 0) {
    foreach ($directors as $row) if ((int)$row['id'] === $editId) $editing = $row;
    if ((int)$editing['id'] === 0) $error = 'Director no encontrado.';
}
$own = $assignments[(int)$editing['id']] ?? [];
$primary = 0; $subs = []; $decs = [];
foreach ($own as $assignment) {
    if ($assignment['assignment_type'] === 'PRINCIPAL') $primary = (int)$assignment['direction_id'];
    else { $subs[] = (int)$assignment['direction_id']; $decs[(int)$assignment['direction_id']] = $assignment['decree_reference']; }
}
$directionNames = [];
foreach ($directions as $direction) $directionNames[(int)$direction['id']] = (string)$direction['name'];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Directores | Administración</title>
<style>
:root{--p:#0b7285;--pd:#075766;--bg:#f3f7fb;--line:#dbe7f1;--text:#17324a;--muted:#60778b;--danger:#b42318}*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",sans-serif;background:var(--bg);color:var(--text)}.shell{display:grid;grid-template-columns:250px 1fr;min-height:100vh}.sidebar{background:#fff;border-right:1px solid var(--line);padding:26px 20px}.brand{margin:0 0 6px}.muted{color:var(--muted)}.menu{display:grid;gap:8px;margin-top:26px}.menu a,.menu summary{display:block;padding:11px 12px;border-radius:10px;text-decoration:none;color:var(--text);font-weight:650;cursor:pointer;list-style:none}.menu summary::-webkit-details-marker{display:none}.menu a.active,.menu summary.active{background:#eaf6f8;color:var(--pd)}.menu summary{display:flex;justify-content:space-between}.menu-group[open] .menu-chevron{transform:rotate(180deg)}.submenu{display:grid;gap:4px;margin:5px 0 4px 14px;padding-left:10px;border-left:2px solid #dcecf0}.submenu a{font-size:.92rem;padding:9px 10px}.main{padding:28px;min-width:0}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.top h1{margin:0}.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:9px;padding:10px 14px;background:var(--p);color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.btn.light{background:#eef4f8;color:var(--text)}.btn.danger{background:#fff0ef;color:var(--danger)}.icon-btn{width:38px;height:38px;padding:0;font-size:1.1rem}.card{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden;margin-bottom:18px}.card-head{padding:17px 20px;border-bottom:1px solid var(--line)}.card-body{padding:20px}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}label{display:block;font-size:.82rem;font-weight:700;margin-bottom:6px}input,select{width:100%;padding:10px;border:1px solid #cddce7;border-radius:8px;font:inherit}.checks{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:8px}.check{display:flex;gap:7px;align-items:center}.check input{width:auto}.sub-item{border:1px solid var(--line);border-radius:10px;padding:11px}.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:15px}.sig{display:block;max-width:170px;max-height:75px;margin-top:8px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:760px}th,td{padding:14px 16px;text-align:left;border-bottom:1px solid #edf2f6;vertical-align:middle}th{font-size:.76rem;text-transform:uppercase;color:var(--muted);background:#f8fafc}.status{display:inline-flex;padding:4px 9px;border-radius:20px;background:#eaf7ef;color:#18794e;font-size:.8rem;font-weight:700}.status.off{background:#f1f5f9;color:#64748b}.alert{padding:12px 15px;border-radius:10px;margin-bottom:16px}.ok{background:#edf9f2}.err{background:#fff0ef;color:var(--danger)}@media(max-width:850px){.shell{display:block}.main{padding:18px}.grid,.checks{grid-template-columns:1fr}.top{align-items:flex-start;gap:12px}}
</style></head><body><div class="shell"><aside class="sidebar"><h2 class="brand">Sistema Honorarios</h2><div class="muted">Perfil administrador</div><?php renderAdminNavigation('directors'); ?></aside><main class="main">
<header class="top"><div><h1>Directores</h1><p class="muted">Administra sus accesos, firma y direcciones habilitadas.</p></div><?php if(!$showForm):?><a class="btn" href="?new=1">Agregar director</a><?php endif;?></header>
<?php if($success):?><div class="alert ok"><?=htmlspecialchars($success)?></div><?php endif;?><?php if($error):?><div class="alert err"><?=htmlspecialchars($error)?></div><?php endif;?>
<?php if($showForm): $isNew=(int)$editing['id']===0; ?><section class="card"><div class="card-head"><strong><?=$isNew?'Agregar director':'Editar director'?></strong></div><div class="card-body"><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="save_director"><input type="hidden" name="profile_id" value="<?=(int)$editing['id']?>"><div class="grid">
<div><label>Nombres</label><input name="first_names" value="<?=htmlspecialchars((string)$editing['first_names'])?>" required autofocus></div><div><label>Apellidos</label><input name="last_names" value="<?=htmlspecialchars((string)$editing['last_names'])?>" required></div><div><label>RUN completo</label><input name="run" value="<?=htmlspecialchars((string)$editing['run'])?>" required></div>
<div><label>Correo</label><input type="email" name="email" value="<?=htmlspecialchars((string)$editing['email'])?>" required></div><div><label>Cargo oficial</label><input name="official_position" value="<?=htmlspecialchars((string)$editing['official_position'])?>" required></div><div><label>Dirección principal</label><select name="principal_direction_id" required><option value="">Seleccione</option><?php foreach($directions as $dir):?><option value="<?=$dir['id']?>" <?=$primary===(int)$dir['id']?'selected':''?>><?=htmlspecialchars($dir['name'])?></option><?php endforeach;?></select></div>
<div><label>Usuario local</label><input name="local_username" value="<?=htmlspecialchars((string)$editing['local_username'])?>" required></div><div><label>Clave local <?=$isNew?'':'(vacía conserva la actual)'?></label><input type="password" name="local_password" <?=$isNew?'required':''?> minlength="8"></div><div><label>Imagen de firma (885 x 293 px o proporcional)</label><input type="file" name="signature_image" accept="image/png,image/jpeg" <?=$isNew?'required':''?>><?php if($editing['signature_path']):?><img class="sig" src="<?=htmlspecialchars($editing['signature_path'])?>" alt="Firma actual"><?php endif;?></div></div>
<h3>Direcciones como subrogante</h3><div class="checks"><?php foreach($directions as $dir):?><div class="sub-item"><label class="check"><input type="checkbox" name="subrogation_direction_ids[]" value="<?=$dir['id']?>" <?=in_array((int)$dir['id'],$subs,true)?'checked':''?>><?=htmlspecialchars($dir['name'])?></label><input name="subrogation_decrees[<?=$dir['id']?>]" value="<?=htmlspecialchars((string)($decs[(int)$dir['id']]??''))?>" placeholder="Decreto o resolución"></div><?php endforeach;?></div>
<div class="actions"><label class="check"><input type="checkbox" name="is_active" <?=$editing['is_active']?'checked':''?>>Habilitado</label><button class="btn">Guardar</button><a class="btn light" href="admin_directores.php">Cancelar</a></div></form><?php if(!$isNew):?><form method="post" onsubmit="return confirm('¿Eliminar este director?')"><input type="hidden" name="action" value="delete_director"><input type="hidden" name="profile_id" value="<?=$editing['id']?>"><button class="btn danger">Eliminar director</button></form><?php endif;?></div></section><?php endif;?>
<section class="card"><div class="card-head"><strong>Directores registrados</strong></div><div class="table-wrap"><table><thead><tr><th>Director</th><th>RUN</th><th>Dirección principal</th><th>Subrogancias</th><th>Estado</th><th></th></tr></thead><tbody><?php foreach($directors as $d): $principalName='Sin asignar';$subCount=0;foreach($assignments[(int)$d['id']]??[] as $a){if($a['assignment_type']==='PRINCIPAL')$principalName=$directionNames[(int)$a['direction_id']]??'Sin asignar';else$subCount++;}?><tr><td><strong><?=htmlspecialchars($d['first_names'].' '.$d['last_names'])?></strong><br><span class="muted"><?=htmlspecialchars($d['official_position'])?></span></td><td><?=htmlspecialchars($d['run'])?></td><td><?=htmlspecialchars($principalName)?></td><td><?=$subCount?></td><td><span class="status <?=$d['is_active']?'':'off'?>"><?=$d['is_active']?'Habilitado':'Deshabilitado'?></span></td><td><a class="btn light icon-btn" href="?edit=<?=$d['id']?>" title="Editar director" aria-label="Editar director">✎</a></td></tr><?php endforeach;?><?php if(!$directors):?><tr><td colspan="6" class="muted">No hay directores registrados.</td></tr><?php endif;?></tbody></table></div></section>
</main></div></body></html>