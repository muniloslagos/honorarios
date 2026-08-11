<?php
declare(strict_types=1);

function renderAdminNavigation(string $active): void
{
    $organizationOpen = in_array($active, ['directions', 'directors'], true);
    ?>
    <nav class="menu admin-menu">
        <a class="<?php echo $active === 'users' ? 'active' : ''; ?>" href="admin.php">Usuarios y permisos</a>
        <details class="menu-group" <?php echo $organizationOpen ? 'open' : ''; ?>>
            <summary class="<?php echo $organizationOpen ? 'active' : ''; ?>">
                <span>Organización</span><span class="menu-chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="submenu">
                <a class="<?php echo $active === 'directions' ? 'active' : ''; ?>" href="admin_direcciones.php">Direcciones</a>
                <a class="<?php echo $active === 'directors' ? 'active' : ''; ?>" href="admin_directores.php">Directores</a>
            </div>
        </details>
        <a class="<?php echo $active === 'smtp' ? 'active' : ''; ?>" href="admin.php#smtp">Configuración SMTP</a>
        <a href="logout.php">Cerrar sesión</a>
    </nav>
    <?php
}

