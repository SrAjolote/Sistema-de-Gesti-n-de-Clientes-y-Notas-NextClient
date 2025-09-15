<?php
require_once 'functions.php';
requireLogin();
requirePermission('admin_full');

$db = new Database();
$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// Manejar acciones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'delete':
            $id = intval($_POST['id']);
            
            // No permitir eliminar el usuario actual
            if ($id === $_SESSION['user_id']) {
                jsonResponse(false, 'No puede eliminar su propio usuario');
            }
            
            try {
                $stmt = $db->prepare("UPDATE usuarios SET activo = 0 WHERE id = ?");
                $stmt->execute([$id]);
                
                logAction($_SESSION['user_id'], 'deactivate', 'usuarios', $id);
                jsonResponse(true, 'Usuario desactivado correctamente');
            } catch (Exception $e) {
                jsonResponse(false, 'Error al desactivar usuario');
            }
            break;
            
        case 'activate':
            $id = intval($_POST['id']);
            try {
                $stmt = $db->prepare("UPDATE usuarios SET activo = 1 WHERE id = ?");
                $stmt->execute([$id]);
                
                logAction($_SESSION['user_id'], 'activate', 'usuarios', $id);
                jsonResponse(true, 'Usuario activado correctamente');
            } catch (Exception $e) {
                jsonResponse(false, 'Error al activar usuario');
            }
            break;
    }
}

// Manejar formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    $nombre = sanitizeInput($_POST['nombre'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $rol = sanitizeInput($_POST['rol'] ?? '');
    $activo = isset($_POST['activo']) ? 1 : 0;
    
    // Validaciones
    if (empty($nombre) || empty($email) || empty($rol)) {
        $error = 'Nombre, email y rol son obligatorios';
    } elseif (!validateEmail($email)) {
        $error = 'Email inválido';
    } elseif (!in_array($rol, ['admin', 'secretario'])) {
        $error = 'Rol inválido';
    } elseif ($action === 'create' && empty($password)) {
        $error = 'La contraseña es obligatoria para nuevos usuarios';
    } elseif (!empty($password) && strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres';
    } else {
        try {
            if ($action === 'create') {
                // Verificar email único
                $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'El email ya está registrado';
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    
                    $stmt = $db->prepare("INSERT INTO usuarios (nombre, email, password, rol, activo, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$nombre, $email, $hashedPassword, $rol, $activo]);
                    
                    $usuarioId = $db->lastInsertId();
                    logAction($_SESSION['user_id'], 'create', 'usuarios', $usuarioId);
                    
                    header('Location: usuario.php?message=Usuario creado correctamente');
                    exit();
                }
            } elseif ($action === 'edit') {
                $id = intval($_POST['id']);
                
                // Verificar email único (excepto el actual)
                $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
                $stmt->execute([$email, $id]);
                if ($stmt->fetch()) {
                    $error = 'El email ya está registrado';
                } else {
                    // No permitir cambiar el rol del usuario actual
                    if ($id === $_SESSION['user_id'] && $rol !== $_SESSION['user_role']) {
                        $error = 'No puede cambiar su propio rol';
                    } else {
                        if (!empty($password)) {
                            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $db->prepare("UPDATE usuarios SET nombre = ?, email = ?, password = ?, rol = ?, activo = ? WHERE id = ?");
                            $stmt->execute([$nombre, $email, $hashedPassword, $rol, $activo, $id]);
                        } else {
                            $stmt = $db->prepare("UPDATE usuarios SET nombre = ?, email = ?, rol = ?, activo = ? WHERE id = ?");
                            $stmt->execute([$nombre, $email, $rol, $activo, $id]);
                        }
                        
                        logAction($_SESSION['user_id'], 'update', 'usuarios', $id);
                        
                        header('Location: usuario.php?message=Usuario actualizado correctamente');
                        exit();
                    }
                }
            }
        } catch (Exception $e) {
            $error = 'Error al procesar la solicitud';
        }
    }
}

// Obtener datos para edición
$usuario = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $usuario = $stmt->fetch();
    
    if (!$usuario) {
        header('Location: usuario.php?error=Usuario no encontrado');
        exit();
    }
}

// Obtener lista de usuarios
$usuarios = [];
if ($action === 'list') {
    $search = $_GET['search'] ?? '';
    $rol_filter = $_GET['rol'] ?? '';
    $activo_filter = $_GET['activo'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    $whereClause = 'WHERE 1=1';
    $params = [];
    
    if (!empty($search)) {
        $whereClause .= " AND (nombre LIKE ? OR email LIKE ?)";
        $searchTerm = "%$search%";
        $params = array_merge($params, [$searchTerm, $searchTerm]);
    }
    
    if (!empty($rol_filter)) {
        $whereClause .= " AND rol = ?";
        $params[] = $rol_filter;
    }
    
    if ($activo_filter !== '') {
        $whereClause .= " AND activo = ?";
        $params[] = intval($activo_filter);
    }
    
    // Contar total
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM usuarios $whereClause");
    $stmt->execute($params);
    $totalUsuarios = $stmt->fetch()['total'];
    $totalPages = ceil($totalUsuarios / $limit);
    
    // Obtener usuarios
    $stmt = $db->prepare("SELECT * FROM usuarios $whereClause ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll();
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
    <title>Gestión de Usuarios - Sistema de Gestión</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-100">
    <!-- Navegación superior -->
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
                    <li class="flex-shrink-0"><a href="usuario.php" class="block p-2 bg-blue-100 text-blue-800 rounded whitespace-nowrap">Usuarios</a></li>
                    <li class="flex-shrink-0"><a href="logs.php" class="block p-2 hover:bg-gray-100 rounded whitespace-nowrap">Logs</a></li>
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
                <!-- Lista de usuarios -->
                <div class="mb-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                        <h2 class="text-xl sm:text-2xl font-bold text-gray-900">Gestión de Usuarios</h2>
                        <a href="usuario.php?action=create" class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm sm:text-base whitespace-nowrap transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            Nuevo Usuario
                        </a>
                    </div>
                    
                    <!-- Filtros -->
                    <div class="mb-4 bg-white p-4 rounded-lg shadow">
                        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <input type="hidden" name="action" value="list">
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Buscar</label>
                                <input type="text" name="search" placeholder="Nombre o email..." 
                                       value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Rol</label>
                                <select name="rol" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">Todos</option>
                                    <option value="admin" <?php echo ($_GET['rol'] ?? '') === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                                    <option value="secretario" <?php echo ($_GET['rol'] ?? '') === 'secretario' ? 'selected' : ''; ?>>Secretario</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Estado</label>
                                <select name="activo" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">Todos</option>
                                    <option value="1" <?php echo ($_GET['activo'] ?? '') === '1' ? 'selected' : ''; ?>>Activo</option>
                                    <option value="0" <?php echo ($_GET['activo'] ?? '') === '0' ? 'selected' : ''; ?>>Inactivo</option>
                                </select>
                            </div>
                            
                            <div class="flex flex-col sm:flex-row items-end gap-2">
                                <button type="submit" class="inline-flex items-center gap-2 bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700 text-sm sm:text-base transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                                    </svg>
                                    Filtrar
                                </button>
                                <a href="usuario.php" class="inline-flex items-center gap-2 bg-gray-400 text-white px-4 py-2 rounded hover:bg-gray-500 text-sm sm:text-base transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                    Limpiar
                                </a>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Tabla de usuarios -->
                    <div class="bg-white rounded-lg shadow overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuario</th>
                                        <th class="hidden sm:table-cell px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                        <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rol</th>
                                        <th class="hidden md:table-cell px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                                        <th class="hidden lg:table-cell px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha</th>
                                        <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                                    </tr>
                                </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($usuarios)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">No hay usuarios registrados</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($usuarios as $user): ?>
                                    <tr class="<?php echo $user['id'] === $_SESSION['user_id'] ? 'bg-blue-50' : ''; ?>">
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-8 w-8 sm:h-10 sm:w-10">
                                                    <div class="h-8 w-8 sm:h-10 sm:w-10 rounded-full bg-gray-300 flex items-center justify-center">
                                                        <span class="text-xs sm:text-sm font-medium text-gray-700">
                                                            <?php echo strtoupper(substr($user['nombre'], 0, 2)); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="ml-2 sm:ml-4">
                                                    <div class="text-xs sm:text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($user['nombre']); ?>
                                                        <?php if ($user['id'] === $_SESSION['user_id']): ?>
                                                            <span class="text-xs text-blue-600">(Usted)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="sm:hidden text-xs text-gray-500">
                                                        <?php echo htmlspecialchars($user['email']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="hidden sm:table-cell px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($user['email']); ?>
                                        </td>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $user['rol'] === 'admin' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800'; ?>">
                                                <?php echo ucfirst($user['rol']); ?>
                                            </span>
                                            <div class="md:hidden text-xs text-gray-500 mt-1">
                                                <?php echo $user['activo'] ? 'Activo' : 'Inactivo'; ?>
                                            </div>
                                        </td>
                                        <td class="hidden md:table-cell px-3 sm:px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $user['activo'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo $user['activo'] ? 'Activo' : 'Inactivo'; ?>
                                            </span>
                                        </td>
                                        <td class="hidden lg:table-cell px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo formatDate($user['created_at']); ?>
                                        </td>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex flex-col gap-1">
                                                <a href="usuario.php?action=edit&id=<?php echo $user['id']; ?>" class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-900 text-xs sm:text-sm transition-colors">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                    </svg>
                                                    Editar
                                                </a>
                                                <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                                                    <?php if ($user['activo']): ?>
                                                        <button onclick="deactivateUser(<?php echo $user['id']; ?>)" class="inline-flex items-center gap-1 text-red-600 hover:text-red-900 text-xs sm:text-sm transition-colors">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636m12.728 12.728L18.364 5.636M5.636 18.364l12.728-12.728"></path>
                                                            </svg>
                                                            Desactivar
                                                        </button>
                                                    <?php else: ?>
                                                        <button onclick="activateUser(<?php echo $user['id']; ?>)" class="inline-flex items-center gap-1 text-green-600 hover:text-green-900 text-xs sm:text-sm transition-colors">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                            </svg>
                                                            Activar
                                                        </button>
                                                    <?php endif; ?>
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
                                        <a href="?action=list&page=<?php echo $page - 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&rol=<?php echo urlencode($_GET['rol'] ?? ''); ?>&activo=<?php echo urlencode($_GET['activo'] ?? ''); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">Anterior</a>
                                    <?php endif; ?>
                                    <?php if ($page < $totalPages): ?>
                                        <a href="?action=list&page=<?php echo $page + 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&rol=<?php echo urlencode($_GET['rol'] ?? ''); ?>&activo=<?php echo urlencode($_GET['activo'] ?? ''); ?>" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">Siguiente</a>
                                    <?php endif; ?>
                                </div>
                                <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                    <div>
                                        <p class="text-sm text-gray-700">
                                            Mostrando <?php echo ($offset + 1); ?> a <?php echo min($offset + $limit, $totalUsuarios); ?> de <?php echo $totalUsuarios; ?> resultados
                                        </p>
                                    </div>
                                    <div>
                                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                                <a href="?action=list&page=<?php echo $i; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&rol=<?php echo urlencode($_GET['rol'] ?? ''); ?>&activo=<?php echo urlencode($_GET['activo'] ?? ''); ?>" 
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
                <!-- Formulario de usuario -->
                <div class="mb-6">
                    <div class="flex items-center mb-4">
                        <a href="usuario.php" class="text-blue-600 hover:text-blue-800 mr-4">← Volver</a>
                        <h2 class="text-2xl font-bold text-gray-900">
                            <?php echo $action === 'create' ? 'Nuevo Usuario' : 'Editar Usuario'; ?>
                        </h2>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-6">
                        <form method="POST" class="space-y-6">
                            <?php if ($action === 'edit'): ?>
                                <input type="hidden" name="id" value="<?php echo $usuario['id']; ?>">
                            <?php endif; ?>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="nombre" class="block text-sm font-medium text-gray-700 mb-2">Nombre *</label>
                                    <input type="text" id="nombre" name="nombre" required 
                                           value="<?php echo htmlspecialchars($usuario['nombre'] ?? $_POST['nombre'] ?? ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                
                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email *</label>
                                    <input type="email" id="email" name="email" required 
                                           value="<?php echo htmlspecialchars($usuario['email'] ?? $_POST['email'] ?? ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                                        Contraseña <?php echo $action === 'create' ? '*' : '(dejar vacío para mantener actual)'; ?>
                                    </label>
                                    <input type="password" id="password" name="password" 
                                           <?php echo $action === 'create' ? 'required' : ''; ?>
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                           placeholder="<?php echo $action === 'create' ? 'Mínimo 6 caracteres' : 'Dejar vacío para no cambiar'; ?>">
                                </div>
                                
                                <div>
                                    <label for="rol" class="block text-sm font-medium text-gray-700 mb-2">Rol *</label>
                                    <select id="rol" name="rol" required 
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                            <?php echo ($action === 'edit' && $usuario['id'] === $_SESSION['user_id']) ? 'disabled' : ''; ?>>
                                        <option value="">Seleccionar rol</option>
                                        <option value="admin" <?php echo (($usuario['rol'] ?? $_POST['rol'] ?? '') === 'admin') ? 'selected' : ''; ?>>Administrador</option>
                                        <option value="secretario" <?php echo (($usuario['rol'] ?? $_POST['rol'] ?? '') === 'secretario') ? 'selected' : ''; ?>>Secretario</option>
                                    </select>
                                    <?php if ($action === 'edit' && $usuario['id'] === $_SESSION['user_id']): ?>
                                        <input type="hidden" name="rol" value="<?php echo $usuario['rol']; ?>">
                                        <p class="text-sm text-gray-500 mt-1">No puede cambiar su propio rol</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div>
                                <label class="flex items-center">
                                    <input type="checkbox" name="activo" value="1" 
                                           <?php echo (($usuario['activo'] ?? $_POST['activo'] ?? 1) == 1) ? 'checked' : ''; ?>
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                    <span class="ml-2 text-sm text-gray-700">Usuario activo</span>
                                </label>
                            </div>
                            
                            <?php if ($action === 'edit'): ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Fecha de Creación</label>
                                        <input type="text" value="<?php echo formatDate($usuario['created_at']); ?>" readonly 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-50">
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="flex flex-col sm:flex-row justify-end gap-4">
                                <a href="usuario.php" class="inline-flex items-center justify-center gap-2 bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600 text-sm sm:text-base transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                    Cancelar
                                </a>
                                <button type="submit" class="inline-flex items-center justify-center gap-2 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm sm:text-base transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    <?php echo $action === 'create' ? 'Crear Usuario' : 'Actualizar Usuario'; ?>
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
        function deactivateUser(id) {
            if (confirm('¿Está seguro de que desea desactivar este usuario?')) {
                fetch('usuario.php', {
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
                    alert('Error al desactivar usuario');
                });
            }
        }
        
        function activateUser(id) {
            if (confirm('¿Está seguro de que desea activar este usuario?')) {
                fetch('usuario.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ajax=1&action=activate&id=${id}`
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
                    alert('Error al activar usuario');
                });
            }
        }
    </script>
</body>
</html>