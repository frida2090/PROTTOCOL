<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/views/dashboard/sidebar.php';

$user = currentUser();
if(!$user){
    redirect('login.php');
}

if($user['rol'] !== 'estudiante') {
    flash('error', 'Acceso denegado. Solo los estudiantes pueden acceder a este panel.');
    redirect('login.php');
}

$pageTitle = 'Panel del Alumno';
require __DIR__ . '/../app/views/header.php';
?>
<div class="dashboard-container">
    <?php renderSidebar('estudiante', 'inicio'); ?>

    <main class="dashboard-main">
        <header class="dashboard-topbar">
            <div>
                <p class="eyebrow">Bienvenido</p>
                <h1>Panel de alumno</h1>
            </div>
            <a class="button button-primary" href="calendario.php">Ver calendario</a>
        </header>

        <section class="dashboard-grid">
            <article class="dashboard-card highlight">
                <p class="dashboard-kicker">Paso 1</p>
                <h2>Protocolo de TT</h2>
                <p class="card-copy">Inicia el proceso para tu Trabajo Terminal subiendo tu Protocolo a la plataforma con los requisitos necesarios.</p>
            </article>

            <article class="dashboard-card">
                <p class="dashboard-kicker">Paso 2</p>
                <h2>Trabajo Terminal I</h2>
                <p class="card-copy">Si cumples con los requisitos necesarios en tu trayectoria escolar, podras inscribir TTI</p>
                <div class="stat-row">
                    <strong>70%</strong>
                    <span>de creditos</span>
                </div>
            </article>

            <article class="dashboard-card">
                <p class="dashboard-kicker">Paso 3</p>
                <h2>Trabajo Terminal II</h2>
                <p class="card-copy">Continúa con el proceso de tu Trabajo Terminal II, una vez que hayas aprobado TTI</p>
            </article>
        </section>

        <section class="dashboard-panel">
            <div class="panel-header">
                <h2>Próximas Fechas</h2>
                <a href="calendario.php">Ver todas</a>
            </div>

            <div class="activity-summary">
                <div class="summary-item">
                    <span class="summary-date">18</span>
                    <div>
                        <strong>Registro de protocolos</strong>
                        <small>Del 21 al 25 de septiembre</small>
                    </div>
                </div>
                <div class="summary-item">
                    <span class="summary-date">03</span>
                    <div>
                        <strong>Primera evaluación</strong>
                        <small>19 al 23 de octubre</small>
                    </div>
                </div>
                <div class="summary-item">
                    <span class="summary-date">14</span>
                    <div>
                        <strong>Publicación de grupos</strong>
                        <small>14 de septiembre</small>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
