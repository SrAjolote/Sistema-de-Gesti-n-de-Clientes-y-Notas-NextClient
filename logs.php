<?php
require_once 'functions.php';
requireLogin();
requirePermission('admin_full');

$db = new Database();
$message = '';
$error = '';

// Obtener logs con filtros
$search = $_GET['search'] ?? '';
$action_filter = $_GET['action'] ?? '';
$table_filter = $_GET['table'] ?? '';
$user_filter = $_GET['user'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$whereClause = 'WHERE 1=1';
$params = [];

if (!empty($search)) {
    $whereClause .= " AND (u.nombre LIKE ? OR l.details LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm]);
}

if (!empty($action_filter)) {
    $whereClause .= " AND l.action = ?";
    $params[] = $action_filter;
}

if (!empty($table_filter)) {
    $whereClause .= " AND l.table_name = ?";
    $params[] = $table_filter;
}

if (!empty($user_filter)) {
    $whereClause .= " AND l.user_id = ?";
    $params[] = $user_filter;
}

if (!empty($date_from)) {
    $whereClause .= " AND DATE(l.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $whereClause .= " AND DATE(l.created_at) <= ?";
    $params[] = $date_to;
}

// Contar total
$stmt = $db->prepare("SELECT COUNT(*) as total FROM logs l LEFT JOIN usuarios u ON l.user_id = u.id $whereClause");
$stmt->execute($params);
$totalLogs = $stmt->fetch()['total'];
$totalPages = ceil($totalLogs / $limit);

// Obtener logs
$stmt = $db->prepare("
    SELECT l.*, u.nombre as user_name, u.email as user_email 
    FROM logs l 
    LEFT JOIN usuarios u ON l.user_id = u.id 
    $whereClause 
    ORDER BY l.created_at DESC 
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Obtener usuarios para filtro
$stmt = $db->prepare("SELECT id, nombre FROM usuarios WHERE activo = 1 ORDER BY nombre");
$stmt->execute();
$usuarios = $stmt->fetchAll();

// Obtener estadísticas
$stmt = $db->prepare("SELECT action, COUNT(*) as count FROM logs WHERE DATE(created_at) = CURDATE() GROUP BY action");
$stmt->execute();
$statsToday = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = $db->prepare("SELECT table_name, COUNT(*) as count FROM logs WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY table_name ORDER BY count DESC LIMIT 5");
$stmt->execute();
$statsWeek = $stmt->fetchAll();

function getActionIcon($action) {
    switch ($action) {
        case 'create': return '<span class="icon icon-add icon-sm"></span>';
        case 'update': return '<span class="icon icon-edit icon-sm"></span>';
        case 'delete': return '<span class="icon icon-delete icon-sm"></span>';
        case 'login': return '<span class="icon icon-key icon-sm"></span>';
        case 'logout': return '<span class="icon icon-logout icon-sm"></span>';
        case 'view': return '<span class="icon icon-view icon-sm"></span>';
        case 'download': return '<span class="icon icon-download icon-sm"></span>';
        case 'activate': return '<span class="icon icon-check icon-sm"></span>';
        case 'deactivate': return '<span class="icon icon-close icon-sm"></span>';
        default: return '<span class="icon icon-edit icon-sm"></span>';
    }
}

function getActionColor($action) {
    switch ($action) {
        case 'create': return 'bg-green-100 text-green-800';
        case 'update': return 'bg-blue-100 text-blue-800';
        case 'delete': return 'bg-red-100 text-red-800';
        case 'login': return 'bg-purple-100 text-purple-800';
        case 'logout': return 'bg-gray-100 text-gray-800';
        case 'view': return 'bg-yellow-100 text-yellow-800';
        case 'download': return 'bg-indigo-100 text-indigo-800';
        case 'activate': return 'bg-green-100 text-green-800';
        case 'deactivate': return 'bg-red-100 text-red-800';
        default: return 'bg-gray-100 text-gray-800';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs del Sistema - Sistema de Gestión</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-100">
    <!-- Navegación -->
    <nav class="bg-slate-900 text-white p-4">
        <div class="container mx-auto flex flex-col sm:flex-row justify-between items-center gap-4">
            <div class="flex items-center space-x-4">
                <img src="logo.png" alt="Logo" class="h-8 w-auto" onerror="this.style.display='none'">
                <h1 class="text-lg sm:text-xl font-bold">Sistema de Gestión</h1>
            </div>
            
            <div class="flex flex-col sm:flex-row items-center gap-2 sm:gap-4">
                <span class="text-sm">Bienvenido, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                <span class="text-xs bg-blue-600 px-2 py-1 rounded"><?php echo ucfirst($_SESSION['user_role']); ?></span>
                <a href="index.php?action=logout" class="text-red-400 hover:text-red-300">Cerrar Sesión</a>
            </div>
        </div>
    </nav>
    
    <!-- Menú lateral -->
    <div class="flex flex-col lg:flex-row min-h-screen">
        <aside class="w-full lg:w-64 bg-white shadow-md">
            <nav class="p-4">
                <ul class="flex lg:flex-col gap-2 overflow-x-auto lg:overflow-x-visible">
                    <li class="flex-shrink-0"><a href="dashboard.php" class="block p-2 hover:bg-gray-100 rounded whitespace-nowrap">Dashboard</a></li>
                    <li class="flex-shrink-0"><a href="cliente.php" class="block p-2 hover:bg-gray-100 rounded whitespace-nowrap">Clientes</a></li>
                    <li class="flex-shrink-0"><a href="nota.php" class="block p-2 hover:bg-gray-100 rounded whitespace-nowrap">Notas</a></li>
                    <li class="flex-shrink-0"><a href="usuario.php" class="block p-2 hover:bg-gray-100 rounded whitespace-nowrap">Usuarios</a></li>
                    <li class="flex-shrink-0"><a href="logs.php" class="block p-2 bg-blue-100 text-blue-800 rounded whitespace-nowrap">Logs</a></li>
                </ul>
            </nav>
        </aside>
        
        <!-- Contenido principal -->
        <main class="flex-1 p-4 lg:p-6">
            <div class="mb-6">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Logs del Sistema</h2>
                
                <!-- Estadísticas -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-4 sm:mb-6">
                    <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                        <h3 class="text-xs sm:text-sm font-medium text-gray-500 mb-2">Actividades Hoy</h3>
                        <div class="space-y-1">
                            <?php if (empty($statsToday)): ?>
                                <p class="text-xs sm:text-sm text-gray-400">Sin actividad</p>
                            <?php else: ?>
                                <?php foreach ($statsToday as $action => $count): ?>
                                    <div class="flex justify-between text-xs sm:text-sm">
                                        <span class="truncate mr-2"><?php echo getActionIcon($action); ?> <span class="hidden sm:inline"><?php echo ucfirst($action); ?></span></span>
                                        <span class="font-medium flex-shrink-0"><?php echo $count; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                        <h3 class="text-xs sm:text-sm font-medium text-gray-500 mb-2">Tablas Más Activas (7 días)</h3>
                        <div class="space-y-1">
                            <?php if (empty($statsWeek)): ?>
                                <p class="text-xs sm:text-sm text-gray-400">Sin actividad</p>
                            <?php else: ?>
                                <?php foreach ($statsWeek as $stat): ?>
                                    <div class="flex justify-between text-xs sm:text-sm">
                                        <span class="truncate mr-2"><?php echo ucfirst($stat['table_name']); ?></span>
                                        <span class="font-medium flex-shrink-0"><?php echo $stat['count']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                        <h3 class="text-xs sm:text-sm font-medium text-gray-500 mb-2">Total de Logs</h3>
                        <p class="text-xl sm:text-2xl font-bold text-gray-900"><?php echo number_format($totalLogs); ?></p>
                    </div>
                    
                    <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                        <h3 class="text-xs sm:text-sm font-medium text-gray-500 mb-2">Páginas</h3>
                        <p class="text-xl sm:text-2xl font-bold text-gray-900"><?php echo $totalPages; ?></p>
                    </div>
                </div>
                
                <!-- Filtros -->
                <div class="mb-4 bg-white p-3 sm:p-4 rounded-lg shadow">
                    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-7 gap-3 sm:gap-4">
                        <div class="sm:col-span-2 md:col-span-1">
                            <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1">Buscar</label>
                            <input type="text" name="search" placeholder="Usuario o detalles..." 
                                   value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                                   class="w-full px-2 sm:px-3 py-1.5 sm:py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1">Acción</label>
                            <select name="action" class="w-full px-2 sm:px-3 py-1.5 sm:py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Todas</option>
                                <option value="create" <?php echo ($_GET['action'] ?? '') === 'create' ? 'selected' : ''; ?>>Crear</option>
                                <option value="update" <?php echo ($_GET['action'] ?? '') === 'update' ? 'selected' : ''; ?>>Actualizar</option>
                                <option value="delete" <?php echo ($_GET['action'] ?? '') === 'delete' ? 'selected' : ''; ?>>Eliminar</option>
                                <option value="login" <?php echo ($_GET['action'] ?? '') === 'login' ? 'selected' : ''; ?>>Login</option>
                                <option value="logout" <?php echo ($_GET['action'] ?? '') === 'logout' ? 'selected' : ''; ?>>Logout</option>
                                <option value="view" <?php echo ($_GET['action'] ?? '') === 'view' ? 'selected' : ''; ?>>Ver</option>
                                <option value="download" <?php echo ($_GET['action'] ?? '') === 'download' ? 'selected' : ''; ?>>Descargar</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1">Tabla</label>
                            <select name="table" class="w-full px-2 sm:px-3 py-1.5 sm:py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Todas</option>
                                <option value="usuarios" <?php echo ($_GET['table'] ?? '') === 'usuarios' ? 'selected' : ''; ?>>Usuarios</option>
                                <option value="clientes" <?php echo ($_GET['table'] ?? '') === 'clientes' ? 'selected' : ''; ?>>Clientes</option>
                                <option value="notas" <?php echo ($_GET['table'] ?? '') === 'notas' ? 'selected' : ''; ?>>Notas</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1">Usuario</label>
                            <select name="user" class="w-full px-2 sm:px-3 py-1.5 sm:py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Todos</option>
                                <?php foreach ($usuarios as $usuario): ?>
                                    <option value="<?php echo $usuario['id']; ?>" <?php echo ($_GET['user'] ?? '') == $usuario['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($usuario['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1">Desde</label>
                            <input type="date" name="date_from" 
                                   value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>"
                                   class="w-full px-2 sm:px-3 py-1.5 sm:py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1">Hasta</label>
                            <input type="date" name="date_to" 
                                   value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>"
                                   class="w-full px-2 sm:px-3 py-1.5 sm:py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div class="flex flex-col sm:flex-row items-end gap-2 xl:col-span-1">
                            <button type="submit" class="w-full sm:w-auto bg-gray-600 text-white px-3 sm:px-4 py-1.5 sm:py-2 text-sm rounded hover:bg-gray-700 transition-colors">Filtrar</button>
                            <a href="logs.php" class="w-full sm:w-auto text-center bg-gray-400 text-white px-3 sm:px-4 py-1.5 sm:py-2 text-sm rounded hover:bg-gray-500 transition-colors">Limpiar</a>
                        </div>
                    </form>
                </div>
                
                <!-- Tabla de logs -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha/Hora</th>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuario</th>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acción</th>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden sm:table-cell">Tabla</th>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">ID Registro</th>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden lg:table-cell">Detalles</th>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden lg:table-cell">IP</th>
                                </tr>
                            </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-4 text-center text-gray-500">No hay logs registrados</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-2 sm:px-3 lg:px-6 py-3 sm:py-4 whitespace-nowrap text-xs sm:text-sm text-gray-900">
                                        <div>
                                            <div class="font-medium"><?php echo date('d/m/Y', strtotime($log['created_at'])); ?></div>
                                            <div class="text-gray-500 text-xs"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></div>
                                        </div>
                                    </td>
                                    <td class="px-2 sm:px-3 lg:px-6 py-3 sm:py-4 whitespace-nowrap text-xs sm:text-sm text-gray-900">
                                        <?php if ($log['user_name']): ?>
                                            <div>
                                                <div class="font-medium truncate max-w-24 sm:max-w-none"><?php echo htmlspecialchars($log['user_name']); ?></div>
                                                <div class="text-gray-500 text-xs hidden sm:block truncate"><?php echo htmlspecialchars($log['user_email']); ?></div>
                                                <div class="sm:hidden text-xs text-gray-500 mt-1">
                                                    <div class="truncate"><?php echo $log['table_name'] ? ucfirst($log['table_name']) : '-'; ?></div>
                                                    <?php if ($log['record_id']): ?><div class="text-xs">#<?php echo $log['record_id']; ?></div><?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-xs">Usuario eliminado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-2 sm:px-3 lg:px-6 py-3 sm:py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-1.5 sm:px-2 py-0.5 rounded-full text-xs font-medium <?php echo getActionColor($log['action']); ?>">
                                            <?php echo getActionIcon($log['action']); ?> <span class="hidden sm:inline ml-1"><?php echo ucfirst($log['action']); ?></span>
                                        </span>
                                    </td>
                                    <td class="px-2 sm:px-3 lg:px-6 py-3 sm:py-4 whitespace-nowrap text-xs sm:text-sm text-gray-900 hidden sm:table-cell">
                                        <?php echo $log['table_name'] ? ucfirst($log['table_name']) : '-'; ?>
                                    </td>
                                    <td class="px-2 sm:px-3 lg:px-6 py-3 sm:py-4 whitespace-nowrap text-xs sm:text-sm text-gray-900 hidden md:table-cell">
                                        <?php echo $log['record_id'] ? '#' . $log['record_id'] : '-'; ?>
                                    </td>
                                    <td class="px-2 sm:px-3 lg:px-6 py-3 sm:py-4 text-xs sm:text-sm text-gray-900 max-w-xs hidden lg:table-cell">
                                        <div class="truncate" title="<?php echo htmlspecialchars($log['details'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($log['details'] ?? '-'); ?>
                                        </div>
                                    </td>
                                    <td class="px-2 sm:px-3 lg:px-6 py-3 sm:py-4 whitespace-nowrap text-xs sm:text-sm text-gray-500 hidden lg:table-cell">
                                        <?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Paginación -->
                    <?php if ($totalPages > 1): ?>
                        <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200">
                            <div class="flex-1 flex justify-between sm:hidden">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&action=<?php echo urlencode($_GET['action'] ?? ''); ?>&table=<?php echo urlencode($_GET['table'] ?? ''); ?>&user=<?php echo urlencode($_GET['user'] ?? ''); ?>&date_from=<?php echo urlencode($_GET['date_from'] ?? ''); ?>&date_to=<?php echo urlencode($_GET['date_to'] ?? ''); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">Anterior</a>
                                <?php endif; ?>
                                <?php if ($page < $totalPages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&action=<?php echo urlencode($_GET['action'] ?? ''); ?>&table=<?php echo urlencode($_GET['table'] ?? ''); ?>&user=<?php echo urlencode($_GET['user'] ?? ''); ?>&date_from=<?php echo urlencode($_GET['date_from'] ?? ''); ?>&date_to=<?php echo urlencode($_GET['date_to'] ?? ''); ?>" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">Siguiente</a>
                                <?php endif; ?>
                            </div>
                            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm text-gray-700">
                                        Mostrando <?php echo ($offset + 1); ?> a <?php echo min($offset + $limit, $totalLogs); ?> de <?php echo $totalLogs; ?> resultados
                                    </p>
                                </div>
                                <div>
                                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&action=<?php echo urlencode($_GET['action'] ?? ''); ?>&table=<?php echo urlencode($_GET['table'] ?? ''); ?>&user=<?php echo urlencode($_GET['user'] ?? ''); ?>&date_from=<?php echo urlencode($_GET['date_from'] ?? ''); ?>&date_to=<?php echo urlencode($_GET['date_to'] ?? ''); ?>" 
                                               class="relative inline-flex items-center px-4 py-2 border text-sm font-medium <?php echo $i === $page ? 'bg-blue-50 border-blue-500 text-blue-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        <?php endfor; ?>
                                    </nav>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <script src="script.js"></script>
</body>
</html>