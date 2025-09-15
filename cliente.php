<?php
require_once 'functions.php';
require_once 'lib/QRGenerator.php';
requireLogin();

$db = new Database();
$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// Manejar acciones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'delete':
            if (!hasPermission('delete_records')) {
                jsonResponse(false, 'Sin permisos para eliminar');
            }
            
            $id = intval($_POST['id']);
            try {
                // Verificar si tiene notas asociadas
                $stmt = $db->prepare("SELECT COUNT(*) as total FROM notas WHERE cliente_id = ?");
                $stmt->execute([$id]);
                if ($stmt->fetch()['total'] > 0) {
                    jsonResponse(false, 'No se puede eliminar: el cliente tiene notas asociadas');
                }
                
                $stmt = $db->prepare("DELETE FROM clientes WHERE id = ?");
                $stmt->execute([$id]);
                
                logAction($_SESSION['user_id'], 'delete', 'clientes', $id);
                jsonResponse(true, 'Cliente eliminado correctamente');
            } catch (Exception $e) {
                jsonResponse(false, 'Error al eliminar cliente');
            }
            break;
            
        case 'generate_qr':
            if (!hasPermission('generate_qr')) {
                jsonResponse(false, 'Sin permisos para generar QR');
            }
            
            $id = intval($_POST['id']);
            try {
                $token = generateQRToken();
                $stmt = $db->prepare("UPDATE clientes SET qr_token = ? WHERE id = ?");
                $stmt->execute([$token, $id]);
                
                $qrGenerator = new QRGenerator();
                $qrUrl = "http://" . $_SERVER['HTTP_HOST'] . "/ver_notas.php?token=" . $token;
                $qrPath = "qr_cliente_" . $id . ".png";
                
                if ($qrGenerator->generarQR($qrUrl, $qrPath)) {
                    logAction($_SESSION['user_id'], 'generate_qr', 'clientes', $id);
                    jsonResponse(true, 'QR generado correctamente', ['qr_path' => $qrPath]);
                } else {
                    jsonResponse(false, 'Error al generar QR');
                }
            } catch (Exception $e) {
                jsonResponse(false, 'Error al generar QR');
            }
            break;
    }
}

// Manejar formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    $nombre = sanitizeInput($_POST['nombre'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $telefono = sanitizeInput($_POST['telefono'] ?? '');
    $direccion = sanitizeInput($_POST['direccion'] ?? '');
    $rfc = sanitizeInput($_POST['rfc'] ?? '');
    
    // Validaciones
    if (empty($nombre) || empty($email)) {
        $error = 'Nombre y email son obligatorios';
    } elseif (!validateEmail($email)) {
        $error = 'Email inválido';
    } else {
        try {
            if ($action === 'create') {
                // Verificar email único
                $stmt = $db->prepare("SELECT id FROM clientes WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'El email ya está registrado';
                } else {
                    $logo_cliente = null;
                    if (isset($_FILES['logo_cliente']) && $_FILES['logo_cliente']['error'] === 0) {
                        $logo_cliente = uploadFile($_FILES['logo_cliente'], 'uploads/logos/');
                    }
                    
                    $stmt = $db->prepare("INSERT INTO clientes (nombre, email, telefono, direccion, rfc, logo_cliente, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$nombre, $email, $telefono, $direccion, $rfc, $logo_cliente]);
                    
                    $clienteId = $db->lastInsertId();
                    logAction($_SESSION['user_id'], 'create', 'clientes', $clienteId);
                    
                    header('Location: cliente.php?message=Cliente creado correctamente');
                    exit();
                }
            } elseif ($action === 'edit') {
                $id = intval($_POST['id']);
                
                // Verificar email único (excepto el actual)
                $stmt = $db->prepare("SELECT id FROM clientes WHERE email = ? AND id != ?");
                $stmt->execute([$email, $id]);
                if ($stmt->fetch()) {
                    $error = 'El email ya está registrado';
                } else {
                    $logo_cliente = $_POST['logo_actual'] ?? null;
                    if (isset($_FILES['logo_cliente']) && $_FILES['logo_cliente']['error'] === 0) {
                        $logo_cliente = uploadFile($_FILES['logo_cliente'], 'uploads/logos/');
                    }
                    
                    $stmt = $db->prepare("UPDATE clientes SET nombre = ?, email = ?, telefono = ?, direccion = ?, rfc = ?, logo_cliente = ? WHERE id = ?");
                    $stmt->execute([$nombre, $email, $telefono, $direccion, $rfc, $logo_cliente, $id]);
                    
                    logAction($_SESSION['user_id'], 'update', 'clientes', $id);
                    
                    header('Location: cliente.php?message=Cliente actualizado correctamente');
                    exit();
                }
            }
        } catch (Exception $e) {
            $error = 'Error al procesar la solicitud';
        }
    }
}

// Obtener datos para edición
$cliente = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $db->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt->execute([$id]);
    $cliente = $stmt->fetch();
    
    if (!$cliente) {
        header('Location: cliente.php?error=Cliente no encontrado');
        exit();
    }
}

// Obtener lista de clientes
$clientes = [];
if ($action === 'list') {
    $search = $_GET['search'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    $whereClause = '';
    $params = [];
    
    if (!empty($search)) {
        $whereClause = "WHERE nombre LIKE ? OR email LIKE ? OR rfc LIKE ?";
        $searchTerm = "%$search%";
        $params = [$searchTerm, $searchTerm, $searchTerm];
    }
    
    // Contar total
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM clientes $whereClause");
    $stmt->execute($params);
    $totalClientes = $stmt->fetch()['total'];
    $totalPages = ceil($totalClientes / $limit);
    
    // Obtener clientes
    $stmt = $db->prepare("SELECT * FROM clientes $whereClause ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $clientes = $stmt->fetchAll();
}

// Mensajes
if (isset($_GET['message'])) {
    $message = $_GET['message'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Clientes - Sistema de Gestión</title>
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
                    <li class="flex-shrink-0"><a href="cliente.php" class="block p-2 bg-blue-100 text-blue-800 rounded whitespace-nowrap">Clientes</a></li>
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
            <?php if ($message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($action === 'list'): ?>
                <!-- Lista de clientes -->
                <div class="mb-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                        <h2 class="text-xl sm:text-2xl font-bold text-gray-900">Gestión de Clientes</h2>
                        <a href="cliente.php?action=create" class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm sm:text-base whitespace-nowrap transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            Nuevo Cliente
                        </a>
                    </div>
                    
                    <!-- Búsqueda -->
                    <div class="mb-4">
                        <form method="GET" class="flex gap-2">
                            <input type="hidden" name="action" value="list">
                            <input type="text" name="search" placeholder="Buscar por nombre, email o RFC..." 
                                   value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <button type="submit" class="inline-flex items-center gap-2 bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700 text-sm sm:text-base transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                                Buscar
                            </button>
                            <?php if (!empty($_GET['search'])): ?>
                                <a href="cliente.php" class="inline-flex items-center gap-2 bg-gray-400 text-white px-4 py-2 rounded hover:bg-gray-500 text-sm sm:text-base transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                    Limpiar
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                    
                    <!-- Tabla de clientes -->
                    <div class="bg-white rounded-lg shadow overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cliente</th>
                                        <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contacto</th>
                                        <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">RFC</th>
                                        <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden lg:table-cell">Fecha</th>
                                        <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                                    </tr>
                                </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($clientes)): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">No hay clientes registrados</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($clientes as $cliente): ?>
                                    <tr>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <?php if ($cliente['logo_cliente'] && file_exists($cliente['logo_cliente'])): ?>
                                                    <img src="<?php echo htmlspecialchars($cliente['logo_cliente']); ?>" alt="Logo" class="h-8 w-8 rounded-full mr-3">
                                                <?php endif; ?>
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($cliente['nombre']); ?></div>
                                                    <div class="text-sm text-gray-500 hidden sm:block"><?php echo htmlspecialchars($cliente['direccion']); ?></div>
                                                    <div class="text-xs text-gray-400 md:hidden"><?php echo htmlspecialchars($cliente['rfc']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($cliente['email']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($cliente['telefono']); ?></div>
                                            <div class="text-xs text-gray-400 lg:hidden"><?php echo formatDate($cliente['created_at']); ?></div>
                                        </td>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900 hidden md:table-cell">
                                            <?php echo htmlspecialchars($cliente['rfc']); ?>
                                        </td>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-500 hidden lg:table-cell">
                                            <?php echo formatDate($cliente['created_at']); ?>
                                        </td>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex flex-col sm:flex-row gap-1 sm:gap-2">
                                                <a href="cliente.php?action=edit&id=<?php echo $cliente['id']; ?>" 
                                                   class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-900 text-xs sm:text-sm transition-colors">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                    </svg>
                                                    Editar
                                                </a>
                                                <?php if (hasPermission('generate_qr')): ?>
                                                    <button onclick="generateQR(<?php echo $cliente['id']; ?>)" 
                                                            class="inline-flex items-center gap-1 text-green-600 hover:text-green-900 text-xs sm:text-sm transition-colors">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path>
                                                        </svg>
                                                        QR
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (hasPermission('delete_records')): ?>
                                                    <button onclick="deleteCliente(<?php echo $cliente['id']; ?>)" 
                                                            class="inline-flex items-center gap-1 text-red-600 hover:text-red-900 text-xs sm:text-sm transition-colors">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                        </svg>
                                                        Eliminar
                                                    </button>
                                                <?php endif; ?>
                                            </div>
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
                                        <a href="?action=list&page=<?php echo $page - 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">Anterior</a>
                                    <?php endif; ?>
                                    <?php if ($page < $totalPages): ?>
                                        <a href="?action=list&page=<?php echo $page + 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">Siguiente</a>
                                    <?php endif; ?>
                                </div>
                                <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                    <div>
                                        <p class="text-sm text-gray-700">
                                            Mostrando <?php echo ($offset + 1); ?> a <?php echo min($offset + $limit, $totalClientes); ?> de <?php echo $totalClientes; ?> resultados
                                        </p>
                                    </div>
                                    <div>
                                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                                <a href="?action=list&page=<?php echo $i; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>" 
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
                
            <?php elseif ($action === 'create' || $action === 'edit'): ?>
                <!-- Formulario de cliente -->
                <div class="mb-6">
                    <div class="flex items-center mb-4">
                        <a href="cliente.php" class="text-blue-600 hover:text-blue-800 mr-4">← Volver</a>
                        <h2 class="text-2xl font-bold text-gray-900">
                            <?php echo $action === 'create' ? 'Nuevo Cliente' : 'Editar Cliente'; ?>
                        </h2>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-6">
                        <form method="POST" enctype="multipart/form-data" class="space-y-6">
                            <?php if ($action === 'edit'): ?>
                                <input type="hidden" name="id" value="<?php echo $cliente['id']; ?>">
                                <input type="hidden" name="logo_actual" value="<?php echo htmlspecialchars($cliente['logo_cliente'] ?? ''); ?>">
                            <?php endif; ?>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="nombre" class="block text-sm font-medium text-gray-700 mb-2">Nombre *</label>
                                    <input type="text" id="nombre" name="nombre" required 
                                           value="<?php echo htmlspecialchars($cliente['nombre'] ?? $_POST['nombre'] ?? ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                
                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email *</label>
                                    <input type="email" id="email" name="email" required 
                                           value="<?php echo htmlspecialchars($cliente['email'] ?? $_POST['email'] ?? ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                
                                <div>
                                    <label for="telefono" class="block text-sm font-medium text-gray-700 mb-2">Teléfono</label>
                                    <input type="text" id="telefono" name="telefono" 
                                           value="<?php echo htmlspecialchars($cliente['telefono'] ?? $_POST['telefono'] ?? ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                
                                <div>
                                    <label for="rfc" class="block text-sm font-medium text-gray-700 mb-2">RFC</label>
                                    <input type="text" id="rfc" name="rfc" 
                                           value="<?php echo htmlspecialchars($cliente['rfc'] ?? $_POST['rfc'] ?? ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                            
                            <div>
                                <label for="direccion" class="block text-sm font-medium text-gray-700 mb-2">Dirección</label>
                                <textarea id="direccion" name="direccion" rows="3" 
                                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo htmlspecialchars($cliente['direccion'] ?? $_POST['direccion'] ?? ''); ?></textarea>
                            </div>
                            
                            <div>
                                <label for="logo_cliente" class="block text-sm font-medium text-gray-700 mb-2">Logo del Cliente</label>
                                <?php if ($action === 'edit' && $cliente['logo_cliente'] && file_exists($cliente['logo_cliente'])): ?>
                                    <div class="mb-2">
                                        <img src="<?php echo htmlspecialchars($cliente['logo_cliente']); ?>" alt="Logo actual" class="h-16 w-auto">
                                        <p class="text-sm text-gray-500">Logo actual</p>
                                    </div>
                                <?php endif; ?>
                                <input type="file" id="logo_cliente" name="logo_cliente" accept="image/*" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <p class="text-sm text-gray-500 mt-1">Formatos permitidos: JPG, PNG, GIF. Tamaño máximo: 5MB</p>
                            </div>
                            
                            <div class="flex flex-col sm:flex-row justify-end gap-4">
                                <a href="cliente.php" class="inline-flex items-center justify-center gap-2 bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600 text-sm sm:text-base transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                    Cancelar
                                </a>
                                <button type="submit" class="inline-flex items-center justify-center gap-2 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm sm:text-base transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    <?php echo $action === 'create' ? 'Crear Cliente' : 'Actualizar Cliente'; ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
    
    <script src="script.js"></script>
    <script>
        function deleteCliente(id) {
            if (confirm('¿Está seguro de que desea eliminar este cliente?')) {
                fetch('cliente.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ajax=1&action=delete&id=${id}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    alert('Error al eliminar cliente');
                });
            }
        }
        
        function generateQR(id) {
            if (confirm('¿Generar nuevo código QR para este cliente?')) {
                fetch('cliente.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ajax=1&action=generate_qr&id=${id}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('QR generado correctamente');
                        if (data.data && data.data.qr_path) {
                            window.open(data.data.qr_path, '_blank');
                        }
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    alert('Error al generar QR');
                });
            }
        }
    </script>
</body>
</html>