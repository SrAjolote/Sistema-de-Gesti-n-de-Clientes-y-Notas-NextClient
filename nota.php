<?php
require_once 'functions.php';
requireLogin();
requirePermission('create_notes');

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
                $db->beginTransaction();
                
                // Eliminar productos relacionados primero
                $stmt = $db->prepare("DELETE FROM nota_productos WHERE nota_id = ?");
                $stmt->execute([$id]);
                
                // Eliminar la nota
                $stmt = $db->prepare("DELETE FROM notas WHERE id = ?");
                $stmt->execute([$id]);
                
                $db->commit();
                logAction($_SESSION['user_id'], 'delete', 'notas', $id);
                jsonResponse(true, 'Nota eliminada correctamente');
            } catch (Exception $e) {
                $db->rollBack();
                jsonResponse(false, 'Error al eliminar nota: ' . $e->getMessage());
            }
            break;
            
        case 'mark_paid':
            $id = intval($_POST['id']);
            try {
                $stmt = $db->prepare("UPDATE notas SET status = 'pagada' WHERE id = ?");
                $stmt->execute([$id]);
                
                logAction($_SESSION['user_id'], 'mark_paid', 'notas', $id);
                jsonResponse(true, 'Nota marcada como pagada');
            } catch (Exception $e) {
                jsonResponse(false, 'Error al marcar como pagada');
            }
            break;
            
        case 'mark_pending':
            $id = intval($_POST['id']);
            try {
                $stmt = $db->prepare("UPDATE notas SET status = 'pendiente' WHERE id = ?");
                $stmt->execute([$id]);
                
                logAction($_SESSION['user_id'], 'mark_pending', 'notas', $id);
                jsonResponse(true, 'Nota marcada como pendiente');
            } catch (Exception $e) {
                jsonResponse(false, 'Error al marcar como pendiente');
            }
            break;
    }
}

// Manejar formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    $cliente_id = intval($_POST['cliente_id'] ?? 0);
    $descripcion = sanitizeInput($_POST['descripcion'] ?? '');
    $total = floatval($_POST['monto'] ?? 0);
    $status = sanitizeInput($_POST['estado'] ?? 'pendiente');
    $productos = $_POST['productos'] ?? [];
    
    // Validaciones
    if ($cliente_id <= 0) {
        $error = 'Debe seleccionar un cliente';
    } elseif (empty($productos) || !is_array($productos)) {
        $error = 'Debe agregar al menos un producto';
    } elseif ($total <= 0) {
        $error = 'El monto debe ser mayor a 0';
    } else {
        try {
            $db->beginTransaction();
            $notaId = null;
            
            if ($action === 'create') {
                $folio = generateFolio($db);
                
                $stmt = $db->prepare("INSERT INTO notas (cliente_id, descripcion, total, status, folio, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$cliente_id, $descripcion, $total, $status, $folio, $_SESSION['user_id']]);
                
                $notaId = $db->lastInsertId();
                logAction($_SESSION['user_id'], 'create', 'notas', $notaId);
            } elseif ($action === 'edit') {
                $id = intval($_POST['id']);
                if ($id <= 0) {
                    throw new Exception('ID de nota inválido');
                }
                
                $stmt = $db->prepare("UPDATE notas SET cliente_id = ?, descripcion = ?, total = ?, status = ? WHERE id = ?");
                $stmt->execute([$cliente_id, $descripcion, $total, $status, $id]);
                
                // Eliminar productos existentes
                $stmt = $db->prepare("DELETE FROM nota_productos WHERE nota_id = ?");
                $stmt->execute([$id]);
                
                $notaId = $id;
                logAction($_SESSION['user_id'], 'update', 'notas', $id);
            }
            
            // Verificar que tenemos un ID válido antes de insertar productos
            if ($notaId && !empty($productos)) {
                $stmt = $db->prepare("INSERT INTO nota_productos (nota_id, linea, articulo, descripcion, cantidad, peso_individual, peso_neto) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $linea = 1;
                foreach ($productos as $producto) {
                    if (!empty($producto['descripcion']) && !empty($producto['cantidad'])) {
                        $precio_unitario = floatval($producto['precio_unitario'] ?? 0);
                        $cantidad = floatval($producto['cantidad']);
                        // La cantidad ya está en kg, así que peso_neto = cantidad
                        // peso_individual es el precio por kg
                        $stmt->execute([
                            $notaId,
                            $linea++,
                            $producto['articulo'] ?? '',
                            $producto['descripcion'],
                            $cantidad,
                            $precio_unitario,
                            $cantidad  // peso_neto = cantidad (ya que cantidad está en kg)
                        ]);
                    }
                }
            }
            
            $db->commit();
            
            $message = $action === 'create' ? 'Nota creada correctamente' : 'Nota actualizada correctamente';
            header("Location: nota.php?message=$message");
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Error al procesar la solicitud: ' . $e->getMessage();
        }
    }
}

// Obtener datos para edición
$nota = null;
$productos_nota = [];
if ($action === 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $db->prepare("SELECT * FROM notas WHERE id = ?");
        $stmt->execute([$id]);
        $nota = $stmt->fetch();
        
        if ($nota) {
            $stmt = $db->prepare("SELECT * FROM nota_productos WHERE nota_id = ? ORDER BY linea");
            $stmt->execute([$id]);
            $productos_nota = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        $error = 'Error al cargar la nota';
    }
}

// Obtener clientes
$clientes = [];
try {
    $stmt = $db->query("SELECT id, nombre FROM clientes ORDER BY nombre");
    $clientes = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Error al cargar clientes';
}

// Obtener notas para listado
$notas = [];
if ($action === 'list') {
    try {
        $stmt = $db->query("
            SELECT n.*, c.nombre as cliente_nombre 
            FROM notas n 
            LEFT JOIN clientes c ON n.cliente_id = c.id 
            ORDER BY n.created_at DESC
        ");
        $notas = $stmt->fetchAll();
    } catch (Exception $e) {
        $error = 'Error al cargar notas';
    }
}

// Mostrar mensaje si existe
if (isset($_GET['message'])) {
    $message = $_GET['message'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Notas - Sistema de Gestión</title>
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
                    <li class="flex-shrink-0"><a href="nota.php" class="block p-2 bg-blue-100 text-blue-800 rounded whitespace-nowrap">Notas</a></li>
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
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                    <h2 class="text-xl sm:text-2xl font-bold text-gray-800">Gestión de Notas</h2>
                    <a href="nota.php?action=create" class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm sm:text-base whitespace-nowrap transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Nueva Nota
                    </a>
                </div>

                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <!-- Tabla responsiva con scroll horizontal en móviles -->
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Folio</th>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden sm:table-cell">Cliente</th>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Descripción</th>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden lg:table-cell">Fecha</th>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($notas as $nota): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <div class="flex flex-col">
                                                <span><?= htmlspecialchars($nota['folio']) ?></span>
                                                <span class="text-xs text-gray-500 sm:hidden"><?= htmlspecialchars($nota['cliente_nombre']) ?></span>
                                            </div>
                                        </td>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900 hidden sm:table-cell"><?= htmlspecialchars($nota['cliente_nombre']) ?></td>
                                        <td class="px-3 sm:px-6 py-4 text-sm text-gray-900 hidden md:table-cell"><?= htmlspecialchars($nota['descripcion']) ?></td>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900">$<?= number_format($nota['total'], 2) ?></td>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $nota['status'] === 'pagada' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
                                                <?= ucfirst($nota['status']) ?>
                                            </span>
                                        </td>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900 hidden lg:table-cell"><?= formatDate($nota['created_at']) ?></td>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex flex-wrap gap-1 sm:gap-2">
                                                <a href="nota.php?action=edit&id=<?= $nota['id'] ?>" class="inline-flex items-center justify-center w-8 h-8 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded transition-colors" title="Editar">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                    </svg>
                                                </a>
                                                <a href="pdf_nota.php?id=<?= $nota['id'] ?>" class="inline-flex items-center justify-center w-8 h-8 text-green-600 hover:text-green-900 hover:bg-green-50 rounded transition-colors" target="_blank" title="PDF">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                    </svg>
                                                </a>
                                                <?php if ($nota['status'] === 'pendiente'): ?>
                                                    <button onclick="markPaid(<?= $nota['id'] ?>)" class="inline-flex items-center justify-center w-8 h-8 text-green-600 hover:text-green-900 hover:bg-green-50 rounded transition-colors" title="Marcar como pagada">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                        </svg>
                                                    </button>
                                                <?php else: ?>
                                                    <button onclick="markPending(<?= $nota['id'] ?>)" class="inline-flex items-center justify-center w-8 h-8 text-yellow-600 hover:text-yellow-900 hover:bg-yellow-50 rounded transition-colors" title="Marcar como pendiente">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                        </svg>
                                                    </button>
                                                <?php endif; ?>
                                                <button onclick="deleteNote(<?= $nota['id'] ?>)" class="inline-flex items-center justify-center w-8 h-8 text-red-600 hover:text-red-900 hover:bg-red-50 rounded transition-colors" title="Eliminar">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php else: ?>
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                    <h2 class="text-xl sm:text-2xl font-bold text-gray-800">
                        <?= $action === 'create' ? 'Nueva Nota' : 'Editar Nota' ?>
                    </h2>
                    <a href="nota.php" class="inline-flex items-center gap-2 bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700 text-sm sm:text-base whitespace-nowrap transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        Volver
                    </a>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <form method="POST">
                        <?php if ($action === 'edit'): ?>
                            <input type="hidden" name="id" value="<?= $nota['id'] ?>">
                        <?php endif; ?>
                        
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6">
                            <div>
                                <label for="cliente_id" class="block text-sm font-medium text-gray-700 mb-2">Cliente *</label>
                                <select class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base" id="cliente_id" name="cliente_id" required>
                                    <option value="">Seleccionar cliente...</option>
                                    <?php foreach ($clientes as $cliente): ?>
                                        <option value="<?= $cliente['id'] ?>" 
                                            <?= ($nota && $nota['cliente_id'] == $cliente['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cliente['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="estado" class="block text-sm font-medium text-gray-700 mb-2">Estado</label>
                                <select class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base" id="estado" name="estado">
                                    <option value="pendiente" <?= ($nota && $nota['status'] === 'pendiente') ? 'selected' : '' ?>>Pendiente</option>
                                    <option value="pagada" <?= ($nota && $nota['status'] === 'pagada') ? 'selected' : '' ?>>Pagada</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-4 lg:mt-6">
                            <label for="descripcion" class="block text-sm font-medium text-gray-700 mb-2">Descripción</label>
                            <textarea class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base" id="descripcion" name="descripcion" rows="3"><?= $nota ? htmlspecialchars($nota['descripcion']) : '' ?></textarea>
                        </div>

                        <div class="mt-4 lg:mt-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Productos *</label>
                            <div id="productos-container" class="space-y-3">
                                <?php if (!empty($productos_nota)): ?>
                                    <?php foreach ($productos_nota as $index => $producto): ?>
                                        <div class="producto-row grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-2 lg:gap-3 p-3 border border-gray-200 rounded">
                                            <input type="text" class="border border-gray-300 rounded px-3 py-2 text-sm sm:text-base" name="productos[<?= $index ?>][articulo]" 
                                                   placeholder="Artículo" value="<?= htmlspecialchars($producto['articulo']) ?>">
                                            <input type="text" class="sm:col-span-1 lg:col-span-2 border border-gray-300 rounded px-3 py-2 text-sm sm:text-base" name="productos[<?= $index ?>][descripcion]" 
                                                   placeholder="Descripción *" value="<?= htmlspecialchars($producto['descripcion']) ?>" required>
                                            <input type="number" class="border border-gray-300 rounded px-3 py-2 cantidad text-sm sm:text-base" name="productos[<?= $index ?>][cantidad]" 
                                                   placeholder="Cantidad (kg) *" value="<?= $producto['cantidad'] ?>" step="0.01" required>
                                            <input type="number" class="border border-gray-300 rounded px-3 py-2 precio text-sm sm:text-base" name="productos[<?= $index ?>][precio_unitario]" 
                                                   placeholder="Precio por kg *" value="<?= $producto['peso_individual'] ?>" step="0.01" required>
                                            <button type="button" class="inline-flex items-center justify-center bg-red-600 text-white px-3 py-2 rounded hover:bg-red-700 remove-producto text-sm sm:text-base transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="producto-row grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-2 lg:gap-3 p-3 border border-gray-200 rounded">
                                        <input type="text" class="border border-gray-300 rounded px-3 py-2 text-sm sm:text-base" name="productos[0][articulo]" placeholder="Artículo">
                                        <input type="text" class="sm:col-span-1 lg:col-span-2 border border-gray-300 rounded px-3 py-2 text-sm sm:text-base" name="productos[0][descripcion]" placeholder="Descripción *" required>
                                        <input type="number" class="border border-gray-300 rounded px-3 py-2 cantidad text-sm sm:text-base" name="productos[0][cantidad]" placeholder="Cantidad (kg) *" step="0.01" required>
                                        <input type="number" class="border border-gray-300 rounded px-3 py-2 precio text-sm sm:text-base" name="productos[0][precio_unitario]" placeholder="Precio por kg *" step="0.01" required>
                                        <button type="button" class="inline-flex items-center justify-center bg-red-600 text-white px-3 py-2 rounded hover:bg-red-700 remove-producto text-sm sm:text-base transition-colors">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <button type="button" id="add-producto" class="mt-3 inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm sm:text-base transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                                Agregar Producto
                            </button>
                        </div>

                        <div class="mt-4 lg:mt-6 grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6">
                            <div>
                                <label for="monto" class="block text-sm font-medium text-gray-700 mb-2">Total *</label>
                                <input type="number" class="w-full border border-gray-300 rounded-md px-3 py-2 bg-gray-100 text-sm sm:text-base" id="monto" name="monto" 
                                       value="<?= $nota ? $nota['total'] : '' ?>" step="0.01" required readonly>
                            </div>
                        </div>

                        <div class="mt-4 lg:mt-6 flex flex-col sm:flex-row gap-4">
                            <button type="submit" class="inline-flex items-center justify-center gap-2 bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 text-sm sm:text-base transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                <?= $action === 'create' ? 'Crear Nota' : 'Actualizar Nota' ?>
                            </button>
                            <a href="nota.php" class="inline-flex items-center justify-center gap-2 bg-gray-600 text-white px-6 py-2 rounded hover:bg-gray-700 text-sm sm:text-base text-center transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                                Cancelar
                            </a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>


    <script>
        let productoIndex = <?= !empty($productos_nota) ? count($productos_nota) : 1 ?>;

        // Agregar producto
        document.getElementById('add-producto').addEventListener('click', function() {
            const container = document.getElementById('productos-container');
            const newRow = document.createElement('div');
            newRow.className = 'producto-row grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-2 lg:gap-3 p-3 border border-gray-200 rounded';
            newRow.innerHTML = `
                <input type="text" class="border border-gray-300 rounded px-3 py-2 text-sm sm:text-base" name="productos[${productoIndex}][articulo]" placeholder="Artículo">
                <input type="text" class="sm:col-span-1 lg:col-span-2 border border-gray-300 rounded px-3 py-2 text-sm sm:text-base" name="productos[${productoIndex}][descripcion]" placeholder="Descripción *" required>
                <input type="number" class="border border-gray-300 rounded px-3 py-2 cantidad text-sm sm:text-base" name="productos[${productoIndex}][cantidad]" placeholder="Cantidad (kg) *" step="0.01" required>
                <input type="number" class="border border-gray-300 rounded px-3 py-2 precio text-sm sm:text-base" name="productos[${productoIndex}][precio_unitario]" placeholder="Precio por kg *" step="0.01" required>
                <button type="button" class="inline-flex items-center justify-center bg-red-600 text-white px-3 py-2 rounded hover:bg-red-700 remove-producto text-sm sm:text-base transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                </button>
            `;
            container.appendChild(newRow);
            productoIndex++;
            updateTotal();
        });

        // Remover producto
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-producto') || e.target.closest('.remove-producto')) {
                const row = e.target.closest('.producto-row');
                if (document.querySelectorAll('.producto-row').length > 1) {
                    row.remove();
                    updateTotal();
                } else {
                    alert('Debe mantener al menos un producto');
                }
            }
        });

        // Actualizar total automáticamente
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('cantidad') || e.target.classList.contains('precio')) {
                updateTotal();
            }
        });

        function updateTotal() {
            let total = 0;
            document.querySelectorAll('.producto-row').forEach(function(row) {
                const cantidad = parseFloat(row.querySelector('.cantidad').value) || 0;
                const precio = parseFloat(row.querySelector('.precio').value) || 0;
                total += cantidad * precio;
            });
            document.getElementById('monto').value = total.toFixed(2);
        }

        // Funciones AJAX
        function deleteNote(id) {
            if (confirm('¿Está seguro de que desea eliminar esta nota?')) {
                fetch('nota.php', {
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
                    alert('Error al eliminar nota');
                });
            }
        }

        function markPaid(id) {
            if (confirm('¿Marcar esta nota como pagada?')) {
                fetch('nota.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ajax=1&action=mark_paid&id=${id}`
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
                    alert('Error al marcar como pagada');
                });
            }
        }

        function markPending(id) {
            if (confirm('¿Marcar esta nota como pendiente?')) {
                fetch('nota.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ajax=1&action=mark_pending&id=${id}`
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
                    alert('Error al marcar como pendiente');
                });
            }
        }

        // Calcular total inicial
        updateTotal();
    </script>
</body>
</html>