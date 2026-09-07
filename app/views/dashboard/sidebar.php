<?php
declare(strict_types=1);

function roleMenuItems(string $role): array
{
    $menuItems = [
        'estudiante' => [
            'inicio' => ['label' => 'Inicio', 'url' => 'panelAlumno.php'],
            'perfil' => ['label' => 'Datos personales', 'url' => 'panelAlumno.php'], //url= perfilAlumno.php?view=perfil
            'tramites' => ['label' => 'Tramites', 'url' => 'panelAlumno.php'], //'perfilAlumno.php?view=tramites' 
            'formatos' => ['label' => 'Formatos', 'url' => 'panelAlumno.php'], //url= perfilAlumno.php?view=formatos 
            'calendario' => ['label' => 'Calendario', 'url' => 'calendario.php'],
            'avisos' => ['label' => 'Avisos', 'url' => 'panelAlumno.php'], //url= perfilAlumno.php?view=avisos        
        ],
        'profesor' => [
            'inicio' => ['label' => 'Inicio', 'url' => 'panelProfesores.php'],
            'perfil' => ['label' => 'Datos personales', 'url' => 'panelProfesores.php'],     
            'calendario' => ['label' => 'Calendario', 'url' => 'calendario.php'],
        ],
        'miembroCatt' => [
            'inicio' => ['label' => 'Inicio', 'url' => 'panelProfesores.php'],
            'perfil' => ['label' => 'Datos personales', 'url' => 'panelProfesores.php'],     
            'calendario' => ['label' => 'Calendario', 'url' => 'calendario.php'], 
        ],
    ];

    return $menuItems[$role] ?? $menuItems['alumno'];
}


function renderSidebar(string $active = 'inicio'): void{
    $user = currentUser();
    if(!$user){
        echo '<p>Usuario no autenticado.</p>';
        return;
    }

    $items = roleMenuItems($user['rol']);
    ?>

    <aside class="dashboard-sidebar"> 
        <div class="sidebar-brand">
            <span class="sidebar-badge">CATT</span>
            <div class="sidebar-user">
                <strong>Panel</strong>
                <small><?=e(ucfirst($user['rol'])) ?? 'Usuario'?></small>
            </div>
        </div>

        <nav class="dashboard-nav" aria-label="Menu lateral">
            <?php foreach ($items as $key => $item): ?>
                <a class="dashboard-link <?= $active === $key ? 'active' : '' ?>" href="<?= e($item['url']) ?>">
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-user-info">
                <p>Sesion Activa:</p>
                <strong><?= e($user['nombre']) ?? 'Usuario' ?></strong>
                <strong><?= e($user['correo']) ?? 'Correo' ?></strong>
        </div>    
    </aside>
    <?php
}
?>