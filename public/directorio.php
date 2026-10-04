<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

$search = trim($_GET['q'] ?? '');
$page = filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$pageSize = 30;
$searchPattern = '%' . $search . '%';
$filterSql = $search === '' ? '' : 'WHERE d.NombreCompleto LIKE ? OR ua.Nombre LIKE ?';
$countQuery = database()->prepare("SELECT COUNT(DISTINCT d.DocenteId) FROM DocentesCatalogo d LEFT JOIN Usuarios u ON u.UserId = d.UserId LEFT JOIN Docente_UnidadAprendizaje du ON du.DocenteId = d.DocenteId LEFT JOIN UnidadAprendizaje ua ON ua.UnidadId = du.UnidadId {$filterSql}");
$countQuery->execute($search === '' ? [] : [$searchPattern, $searchPattern]);
$totalRows = (int) $countQuery->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;
$query = database()->prepare("SELECT d.DocenteId, d.NombreCompleto, u.Email AS correo, GROUP_CONCAT(DISTINCT ua.Nombre ORDER BY ua.Nombre SEPARATOR ', ') AS unidades FROM DocentesCatalogo d LEFT JOIN Usuarios u ON u.UserId = d.UserId LEFT JOIN Docente_UnidadAprendizaje du ON du.DocenteId = d.DocenteId LEFT JOIN UnidadAprendizaje ua ON ua.UnidadId = du.UnidadId {$filterSql} GROUP BY d.DocenteId, d.NombreCompleto, u.Email ORDER BY d.NombreCompleto LIMIT {$pageSize} OFFSET {$offset}");
$query->execute($search === '' ? [] : [$searchPattern, $searchPattern]);
$records = $query->fetchAll();

$pageTitle = 'Directorio académico';
require __DIR__ . '/../app/views/header.php';
?>
<section class="calendar-heading">
    <div><p class="eyebrow">ESCOM · CATT</p><h1>Directorio académico</h1><p>Docentes y unidades de aprendizaje registrados.</p></div>
</section>
<section class="directory-section">
    <form method="get" class="directory-search">
        <label for="q">Buscar en el catálogo</label>
        <div><input id="q" name="q" type="search" value="<?= e($search) ?>" placeholder="Nombre de profesor o unidad"><button class="button button-primary" type="submit">Buscar</button><?php if ($search !== ''): ?><a class="button button-dark" href="directorio.php">Limpiar</a><?php endif; ?></div>
    </form>
    <p class="directory-count"><?= number_format($totalRows) ?> profesores<?php if ($search !== ''): ?> para “<?= e($search) ?>”<?php endif; ?></p>
    <?php if (!$records): ?>
        <div class="empty-state"><h2>No hay coincidencias</h2><p>Prueba con otro nombre o unidad de aprendizaje.</p></div>
    <?php else: ?>
        <div class="directory-table-wrap">
            <table class="directory-table">
                <thead><tr><th>Profesor</th><th>Unidades de aprendizaje</th><th>Cuenta PROTTOCOL</th></tr></thead>
                <tbody><?php foreach ($records as $record): ?><tr><td><?= e($record['NombreCompleto']) ?></td><td><?= e($record['unidades'] ?: 'Sin unidades registradas') ?></td><td><?= $record['correo'] ? e($record['correo']) : 'Sin cuenta vinculada' ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <nav class="directory-pagination" aria-label="Paginación">
            <?php if ($page > 1): ?><a class="button button-dark" href="?<?= e(http_build_query(['q' => $search, 'pagina' => $page - 1])) ?>" aria-label="Página anterior">← Anterior</a><?php endif; ?>
            <span>Página <?= $page ?> de <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?><a class="button button-dark" href="?<?= e(http_build_query(['q' => $search, 'pagina' => $page + 1])) ?>" aria-label="Página siguiente">Siguiente →</a><?php endif; ?>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<style>
.directory-section { max-width: 1100px; margin: 28px auto 64px; }
.directory-search { display: grid; gap: 8px; max-width: 660px; }
.directory-search > div { display: flex; flex-wrap: wrap; gap: 8px; }
.directory-search input { flex: 1 1 260px; min-width: 0; }
.directory-count { color: var(--muted); font-size: .9rem; margin: 22px 0 10px; }
.directory-table-wrap { max-width: 100%; overflow-x: auto; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
.directory-table { border-collapse: collapse; width: 100%; text-align: left; }
.directory-table th, .directory-table td { padding: 12px 14px; border-bottom: 1px solid var(--line); vertical-align: top; }
.directory-table th { color: var(--muted); font-size: .75rem; text-transform: uppercase; }
.directory-table td:first-child { min-width: 260px; font-weight: 600; }
.directory-table td:last-child { min-width: 220px; }
.directory-table td:nth-child(3) { min-width: 230px; }
.directory-pagination { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 18px; }
@media (max-width: 560px) { .directory-pagination { flex-wrap: wrap; } .directory-pagination span { order: -1; flex-basis: 100%; } }
</style>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
