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

function directionCode(string $name): string
{
    $plain = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $plain) ?? '');
    return trim(substr($code, 0, 40), '-') ?: 'DIRECCION-' . date('His');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['direction_id'] ?? 0);
        if ($action === 'save') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $mailboxType = strtoupper(trim((string) ($_POST['mailbox_type'] ?? 'DIRECCION')));
            $active = isset($_POST['is_active']) ? 1 : 0;
            if (!in_array($mailboxType, ['DIRECCION', 'DEPARTAMENTO'], true)) throw new RuntimeException('El tipo de buzón no es válido.');
            if ($name === '') throw new RuntimeException('El nombre de la dirección es obligatorio.');
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE directions SET name=:name,mailbox_type=:mailbox_type,is_active=:active WHERE id=:id');
                $stmt->execute(['name'=>$name,'mailbox_type'=>$mailboxType,'active'=>$active,'id'=>$id]);
            } else {
                $baseCode = directionCode($name);
                $code = $baseCode;
                $suffix = 2;
                $check = $pdo->prepare('SELECT COUNT(*) FROM directions WHERE code=:code');
                do {
                    $check->execute(['code'=>$code]);
                    if ((int) $check->fetchColumn() === 0) break;
                    $code = substr($baseCode, 0, 35) . '-' . $suffix++;
                } while ($suffix < 1000);
                $stmt = $pdo->prepare('INSERT INTO directions(name,code,mailbox_type,is_active) VALUES (:name,:code,:mailbox_type,:active)');
                $stmt->execute(['name'=>$name,'code'=>$code,'mailbox_type'=>$mailboxType,'active'=>$active]);
            }
            $success = 'Dirección guardada correctamente.';
        } elseif ($action === 'delete') {
            if ($id < 1) throw new RuntimeException('Dirección no encontrada.');
            $pdo->prepare('DELETE FROM directions WHERE id=:id')->execute(['id'=>$id]);
            $success = 'Dirección eliminada.';
        }
    }
} catch (Throwable $e) {
    $error = $e instanceof PDOException && (string) $e->getCode() === '23000'
        ? 'No se puede eliminar esta dirección porque tiene usuarios, documentos o directores asociados.'
        : $e->getMessage();
}

$directions = $pdo->query("SELECT d.*,
    (SELECT COUNT(*) FROM system_users u WHERE u.direction_id=d.id) AS users_count,
    (SELECT COUNT(*) FROM director_directions dd WHERE dd.direction_id=d.id) AS directors_count
    FROM directions d ORDER BY d.name")->fetchAll();
$editId = (int) ($_GET['edit'] ?? 0);
$showForm = ($success === '') && (isset($_GET['new']) || $editId > 0 || $error !== '');
$editing = null;
if ($editId > 0) {
    foreach ($directions as $row) if ((int)$row['id'] === $editId) $editing = $row;
    if ($editing === null) $error = 'Dirección no encontrada.';
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Direcciones | Administración</title>
<style>
:root{--p:#0b7285;--pd:#075766;--bg:#f3f7fb;--line:#dbe7f1;--text:#17324a;--muted:#60778b;--danger:#b42318}*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",sans-serif;background:var(--bg);color:var(--text)}.shell{display:grid;grid-template-columns:250px 1fr;min-height:100vh}.sidebar{background:#fff;border-right:1px solid var(--line);padding:26px 20px}.brand{margin:0 0 6px}.muted{color:var(--muted)}.menu{display:grid;gap:8px;margin-top:26px}.menu a,.menu summary{display:block;padding:11px 12px;border-radius:10px;text-decoration:none;color:var(--text);font-weight:650;cursor:pointer;list-style:none}.menu summary::-webkit-details-marker{display:none}.menu a.active,.menu summary.active{background:#eaf6f8;color:var(--pd)}.menu summary{display:flex;justify-content:space-between}.menu-group[open] .menu-chevron{transform:rotate(180deg)}.submenu{display:grid;gap:4px;margin:5px 0 4px 14px;padding-left:10px;border-left:2px solid #dcecf0}.submenu a{font-size:.92rem;padding:9px 10px}.main{padding:28px;min-width:0}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.top h1{margin:0}.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:9px;padding:10px 14px;background:var(--p);color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.btn.light{background:#eef4f8;color:var(--text)}.btn.danger{background:#fff0ef;color:var(--danger)}.icon-btn{width:38px;height:38px;padding:0;font-size:1.1rem}.card{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden;margin-bottom:18px}.card-head{padding:17px 20px;border-bottom:1px solid var(--line)}.form{padding:20px;display:grid;grid-template-columns:2fr 1fr auto;gap:14px;align-items:end}label{display:block;font-size:.82rem;font-weight:700;margin-bottom:6px}input,select{width:100%;padding:10px;border:1px solid #cddce7;border-radius:8px;font:inherit}.switch{display:flex;gap:7px;align-items:center}.switch input{width:auto}.actions{display:flex;gap:8px;align-items:center}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:14px 16px;text-align:left;border-bottom:1px solid #edf2f6}th{font-size:.76rem;text-transform:uppercase;color:var(--muted);background:#f8fafc}.status{display:inline-flex;padding:4px 9px;border-radius:20px;background:#eaf7ef;color:#18794e;font-size:.8rem;font-weight:700}.status.off{background:#f1f5f9;color:#64748b}.alert{padding:12px 15px;border-radius:10px;margin-bottom:16px}.ok{background:#edf9f2}.err{background:#fff0ef;color:var(--danger)}@media(max-width:800px){.shell{display:block}.main{padding:18px}.form{grid-template-columns:1fr}.top{align-items:flex-start;gap:12px}}
</style></head><body><div class="shell"><aside class="sidebar"><h2 class="brand">Sistema Honorarios</h2><div class="muted">Perfil administrador</div><?php renderAdminNavigation('directions'); ?></aside><main class="main">
<header class="top"><div><h1>Direcciones</h1><p class="muted">Administra las direcciones que recibirán documentos.</p></div><?php if(!$showForm):?><a class="btn" href="?new=1">Agregar dirección</a><?php endif;?></header>
<?php if($success):?><div class="alert ok"><?=htmlspecialchars($success)?></div><?php endif;?><?php if($error):?><div class="alert err"><?=htmlspecialchars($error)?></div><?php endif;?>
<?php if($showForm):?><section class="card"><div class="card-head"><strong><?=$editing?'Editar dirección':'Agregar dirección'?></strong></div><form method="post" class="form"><input type="hidden" name="action" value="save"><input type="hidden" name="direction_id" value="<?=(int)($editing['id']??0)?>"><div><label>Nombre de la dirección o departamento</label><input name="name" value="<?=htmlspecialchars((string)($editing['name']??''))?>" required autofocus></div><div><label>Tipo de buzón</label><select name="mailbox_type"><option value="DIRECCION" <?=($editing['mailbox_type']??'DIRECCION')==='DIRECCION'?'selected':''?>>Dirección</option><option value="DEPARTAMENTO" <?=($editing['mailbox_type']??'DIRECCION')==='DEPARTAMENTO'?'selected':''?>>Departamento</option></select></div><div><label class="switch"><input type="checkbox" name="is_active" <?=!$editing||(int)$editing['is_active']===1?'checked':''?>>Habilitada</label><div class="actions"><button class="btn">Guardar</button><a class="btn light" href="admin_direcciones.php">Cancelar</a></div></div></form><?php if($editing):?><form method="post" style="padding:0 20px 20px" onsubmit="return confirm('¿Eliminar esta dirección?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="direction_id" value="<?=$editing['id']?>"><button class="btn danger">Eliminar dirección</button></form><?php endif;?></section><?php endif;?>
<section class="card"><div class="card-head"><strong>Direcciones registradas</strong></div><div class="table-wrap"><table><thead><tr><th>Dirección</th><th>Tipo</th><th>Estado</th><th>Usuarios</th><th>Directores</th><th></th></tr></thead><tbody><?php foreach($directions as $d):?><tr><td><strong><?=htmlspecialchars($d['name'])?></strong></td><td><?=($d['mailbox_type']??'DIRECCION')==='DEPARTAMENTO'?'Departamento':'Dirección'?></td><td><span class="status <?=$d['is_active']?'':'off'?>"><?=$d['is_active']?'Habilitada':'Deshabilitada'?></span></td><td><?=$d['users_count']?></td><td><?=$d['directors_count']?></td><td><a class="btn light icon-btn" href="?edit=<?=$d['id']?>" title="Editar dirección" aria-label="Editar dirección">✎</a></td></tr><?php endforeach;?><?php if(!$directions):?><tr><td colspan="6" class="muted">No hay direcciones registradas.</td></tr><?php endif;?></tbody></table></div></section>
</main></div></body></html>
