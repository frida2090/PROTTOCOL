<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
$pageTitle = 'Inicio';
require __DIR__ . '/../app/views/header.php';
?>
<section id="inicio" class="home-hero">
    <div class="hero-copy">
        <p class="eyebrow">ESCOM · Comunidad estudiantil</p>
        <h1>Tu vida académica, <em>en un solo lugar.</em></h1>
        <p>Bienvenido a la plataforma de CATT para las alumnas y alumnos de la Escuela Superior de Cómputo. Encuentra procesos, formularios y actividades sin perderte entre avisos.</p>
        <div class="hero-actions"><a class="button button-primary" href="registro.php">Crear una cuenta <span>→</span></a><a class="text-link" href="login.php">Ya tengo una cuenta</a></div>
    </div>
    <div class="hero-panel"><span class="hero-number">01</span><p>ESCOM</p><h2>Conecta.<br>Participa.<br>Avanza.</h2><div class="hero-rule"></div><small>Centro de apoyo y trabajo colaborativo</small></div>
</section>
<section id="procesos" class="home-section process-section"><div class="section-heading">
    <div class="section-heading-image-container">
        <p class="eyebrow">Acompañamiento</p>
        <img src="assets/img/acompañamiento.webp" alt="Acompañamiento en CATT" class="section-heading-image">
    </div>
    <h2>Lo que necesitas,<br><em>cuando lo necesitas.</em></h2>
    <p>Un punto de partida claro para resolver tus pendientes académicos y participar activamente en tu comunidad.</p></div><div class="feature-grid"><article class="feature-item"><span class="feature-index">01</span><h3>Procesos</h3><p>Consulta rutas y pasos para realizar tus trámites escolares con mayor seguridad.</p><a href="#procesos">Conocer más <span>↗</span></a></article><article class="feature-item feature-highlight"><span class="feature-index">02</span><h3>Formularios</h3><p>Accede rápidamente a los formatos que necesitas para tus actividades dentro de ESCOM.</p><a href="#formularios">Ver formularios <span>↗</span></a></article><article class="feature-item"><span class="feature-index">03</span><h3>Actividades</h3><p>Entérate de talleres, reuniones y eventos de CATT desde nuestro calendario.</p><a href="calendario.php">Ver calendario <span>↗</span></a></article></div></section>
<section id="formularios" class="home-section forms-section"><div class="forms-intro"><p class="eyebrow">Recursos ESCOM</p><h2>Formularios que<br><em>te ahorran tiempo.</em></h2><p>Estamos organizando los formatos esenciales para que puedas concentrarte en lo importante.</p></div><div class="form-links"><a href="registro.php"><span>Registro a la plataforma</span><b>↗</b></a><a href="login.php"><span>Acceso a tu cuenta</span><b>↗</b></a><a href="calendario.php"><span>Agenda de actividades</span><b>↗</b></a></div></section>
<section id="comunidad" class="home-cta"><p class="eyebrow">Comunidad CATT</p><h2>Haz que tu semestre<br>también cuente.</h2><a class="button button-gold" href="registro.php">Únete a ESCOM <span>→</span></a></section>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
