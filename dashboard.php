<?php
// Habilitar reporte de errores detallado
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

try {
    require_once 'functions.php';
    echo "<!-- functions.php cargado correctamente -->\n";
} catch (Exception $e) {
    die("Error cargando functions.php: " . $e->getMessage());
}

try {
    requireLogin();
    echo "<!-- requireLogin() ejecutado correctamente -->\n";
} catch (Exception $e) {
    die("Error en requireLogin(): " . $e->getMessage());
}

try {
    $db = new Database();
    echo "<!-- Database conectada correctamente -->\n";
} catch (Exception $e) {
    die("Error conectando a la base de datos: " . $e->getMessage());
}

// Obtener estadísticas
$stats = [];

try {
    // Total de clientes
    $stmt = $db->query("SELECT COUNT(*) as total FROM clientes");
    $stats['clientes'] = $stmt->fetch()['total'];
    echo "<!-- Estadística clientes obtenida: " . $stats['clientes'] . " -->\n";
} catch (Exception $e) {
    echo "<!-- Error obteniendo clientes: " . $e->getMessage() . " -->\n";
    $stats['clientes'] = 0;
}

try {
    // Total de notas
    $stmt = $db->query("SELECT COUNT(*) as total FROM notas");
    $stats['notas'] = $stmt->fetch()['total'];
    echo "<!-- Estadística notas obtenida: " . $stats['notas'] . " -->\n";
} catch (Exception $e) {
    echo "<!-- Error obteniendo notas: " . $e->getMessage() . " -->\n";
    $stats['notas'] = 0;
}

try {
    // Notas pendientes
    $stmt = $db->query("SELECT COUNT(*) as total FROM notas WHERE status = 'pendiente'");
    $stats['pendientes'] = $stmt->fetch()['total'];
    echo "<!-- Estadística pendientes obtenida: " . $stats['pendientes'] . " -->\n";
} catch (Exception $e) {
    echo "<!-- Error obteniendo pendientes: " . $e->getMessage() . " -->\n";
    $stats['pendientes'] = 0;
}

try {
    // Ingresos del mes
    $stmt = $db->query("SELECT SUM(total) as total FROM notas WHERE status = 'pagada' AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())");
    $stats['ingresos_mes'] = $stmt->fetch()['total'] ?? 0;
    echo "<!-- Estadística ingresos obtenida: " . $stats['ingresos_mes'] . " -->\n";
} catch (Exception $e) {
    echo "<!-- Error obteniendo ingresos: " . $e->getMessage() . " -->\n";
    $stats['ingresos_mes'] = 0;
}

try {
    // Últimas notas
    $stmt = $db->query("SELECT n.*, c.nombre as cliente_nombre FROM notas n LEFT JOIN clientes c ON n.cliente_id = c.id ORDER BY n.created_at DESC LIMIT 5");
    $ultimas_notas = $stmt->fetchAll();
    echo "<!-- Últimas notas obtenidas: " . count($ultimas_notas) . " -->\n";
} catch (Exception $e) {
    echo "<!-- Error obteniendo últimas notas: " . $e->getMessage() . " -->\n";
    $ultimas_notas = [];
}

try {
    // Clientes recientes
    $stmt = $db->query("SELECT * FROM clientes ORDER BY created_at DESC LIMIT 5");
    $clientes_recientes = $stmt->fetchAll();
    echo "<!-- Clientes recientes obtenidos: " . count($clientes_recientes) . " -->\n";
} catch (Exception $e) {
    echo "<!-- Error obteniendo clientes recientes: " . $e->getMessage() . " -->\n";
    $clientes_recientes = [];
}

echo "<!-- Todas las consultas completadas -->\n";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistema de Gestión</title>
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
                    <li class="flex-shrink-0"><a href="dashboard.php" class="block p-2 bg-blue-100 text-blue-800 rounded whitespace-nowrap">Dashboard</a></li>
                    <li class="flex-shrink-0"><a href="cliente.php" class="block p-2 hover:bg-gray-100 rounded whitespace-nowrap">Clientes</a></li>
                    <li class="flex-shrink-0"><a href="nota.php" class="block p-2 hover:bg-gray-100 rounded whitespace-nowrap">Notas</a></li>
                    <?php if (hasPermission('admin_full')): ?>
                    <li class="flex-shrink-0"><a href="usuario.php" class="block p-2 hover:bg-gray-100 rounded whitespace-nowrap">Usuarios</a></li>
                    <li class="flex-shrink-0"><a href="logs.php" class="block p-2 hover:bg-gray-100 rounded whitespace-nowrap">Logs</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </aside>
        
        <!-- Contenido principal -->
        <main class="flex-1 p-4 lg:p-6">
            <div class="mb-6">
                <h2 class="text-2xl font-bold text-gray-900">Dashboard</h2>
                <p class="text-gray-600">Resumen general del sistema</p>
            </div>
            
            <!-- Estadísticas -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-8">
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow">
                    <div class="flex items-center">
                        <div class="p-2 bg-blue-100 rounded-lg flex-shrink-0">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                        </div>
                        <div class="ml-3 sm:ml-4 min-w-0">
                            <p class="text-xs sm:text-sm font-medium text-gray-600 truncate">Total Clientes</p>
                            <p class="text-xl sm:text-2xl font-bold text-gray-900"><?php echo $stats['clientes']; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow">
                    <div class="flex items-center">
                        <div class="p-2 bg-green-100 rounded-lg flex-shrink-0">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <div class="ml-3 sm:ml-4 min-w-0">
                            <p class="text-xs sm:text-sm font-medium text-gray-600 truncate">Total Notas</p>
                            <p class="text-xl sm:text-2xl font-bold text-gray-900"><?php echo $stats['notas']; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow">
                    <div class="flex items-center">
                        <div class="p-2 bg-yellow-100 rounded-lg flex-shrink-0">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div class="ml-3 sm:ml-4 min-w-0">
                            <p class="text-xs sm:text-sm font-medium text-gray-600 truncate">Notas Pendientes</p>
                            <p class="text-xl sm:text-2xl font-bold text-gray-900"><?php echo $stats['pendientes']; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow">
                    <div class="flex items-center">
                        <div class="p-2 bg-purple-100 rounded-lg flex-shrink-0">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                            </svg>
                        </div>
                        <div class="ml-3 sm:ml-4 min-w-0">
                            <p class="text-xs sm:text-sm font-medium text-gray-600 truncate">Ingresos del Mes</p>
                            <p class="text-xl sm:text-2xl font-bold text-gray-900"><?php echo formatCurrency($stats['ingresos_mes']); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Contenido en dos columnas -->
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 sm:gap-6">
                <!-- Últimas notas -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-4 sm:p-6 border-b border-gray-200">
                        <h3 class="text-base sm:text-lg font-medium text-gray-900">Últimas Notas</h3>
                    </div>
                    <div class="p-4 sm:p-6">
                        <?php if (empty($ultimas_notas)): ?>
                            <p class="text-gray-500 text-center py-4">No hay notas registradas</p>
                        <?php else: ?>
                            <div class="space-y-3 sm:space-y-4">
                                <?php foreach ($ultimas_notas as $nota): ?>
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between p-3 bg-gray-50 rounded gap-2 sm:gap-0">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm sm:text-base font-medium truncate"><?php echo htmlspecialchars($nota['cliente_nombre'] ?? 'Cliente eliminado'); ?></p>
                                        <p class="text-xs sm:text-sm text-gray-600">Folio: <?php echo htmlspecialchars($nota['folio']); ?></p>
                                        <p class="text-xs sm:text-sm text-gray-600"><?php echo formatDate($nota['created_at']); ?></p>
                                    </div>
                                    <div class="text-left sm:text-right flex-shrink-0">
                                        <p class="text-sm sm:text-base font-bold"><?php echo formatCurrency($nota['total']); ?></p>
                                        <span class="inline-block px-2 py-1 text-xs rounded <?php echo $nota['status'] === 'pagada' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                            <?php echo ucfirst($nota['status'] === 'pagada' ? 'pagado' : $nota['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="mt-4 text-center">
                            <a href="nota.php" class="text-blue-600 hover:text-blue-800">Ver todas las notas →</a>
                        </div>
                    </div>
                </div>
                
                <!-- Clientes recientes -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-4 sm:p-6 border-b border-gray-200">
                        <h3 class="text-base sm:text-lg font-medium text-gray-900">Clientes Recientes</h3>
                    </div>
                    <div class="p-4 sm:p-6">
                        <?php if (empty($clientes_recientes)): ?>
                            <p class="text-gray-500 text-center py-4">No hay clientes registrados</p>
                        <?php else: ?>
                            <div class="space-y-3 sm:space-y-4">
                                <?php foreach ($clientes_recientes as $cliente): ?>
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between p-3 bg-gray-50 rounded gap-2 sm:gap-0">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm sm:text-base font-medium truncate"><?php echo htmlspecialchars($cliente['nombre']); ?></p>
                                        <p class="text-xs sm:text-sm text-gray-600 truncate"><?php echo htmlspecialchars($cliente['email']); ?></p>
                                        <p class="text-xs sm:text-sm text-gray-600"><?php echo formatDate($cliente['created_at']); ?></p>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <a href="cliente.php?action=edit&id=<?php echo $cliente['id']; ?>" class="text-blue-600 hover:text-blue-800 text-xs sm:text-sm">Editar</a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="mt-4 text-center">
                            <a href="cliente.php" class="text-blue-600 hover:text-blue-800">Ver todos los clientes →</a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="script.js"></script>
</body>
</html>