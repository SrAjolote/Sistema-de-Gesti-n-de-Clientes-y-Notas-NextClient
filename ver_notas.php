<?php
// Habilitar reporte de errores detallado
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

try {
    require_once 'functions.php';
} catch (Exception $e) {
    die("Error cargando functions.php: " . $e->getMessage());
}

// Verificar que se proporcione un token
if (!isset($_GET['token']) || empty($_GET['token'])) {
    http_response_code(404);
    die('Token no válido');
}

$token = $_GET['token'];

try {
    $db = new Database();
    
    // Buscar cliente por token
    $stmt = $db->prepare("SELECT * FROM clientes WHERE qr_token = ?");
    $stmt->execute([$token]);
    $cliente = $stmt->fetch();
    
    if (!$cliente) {
        http_response_code(404);
        die('Cliente no encontrado');
    }
    
    // Obtener notas del cliente
    $stmt = $db->prepare("
        SELECT id, folio, descripcion, total, status, created_at 
        FROM notas 
        WHERE cliente_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$cliente['id']]);
    $notas = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log('Error en ver_notas.php: ' . $e->getMessage());
    http_response_code(500);
    die('Error interno del servidor');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notas de <?php echo htmlspecialchars($cliente['nombre']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-100">
    <div class="min-h-screen py-8">
        <div class="max-w-4xl mx-auto px-4">
            <!-- Header -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Notas de <?php echo htmlspecialchars($cliente['nombre']); ?></h1>
                        <p class="text-gray-600 mt-1"><?php echo htmlspecialchars($cliente['email']); ?></p>
                        <?php if ($cliente['telefono']): ?>
                            <p class="text-gray-600"><?php echo htmlspecialchars($cliente['telefono']); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($cliente['logo_cliente']): ?>
                        <img src="<?php echo htmlspecialchars($cliente['logo_cliente']); ?>" alt="Logo" class="h-16 w-auto">
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Notas -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Historial de Notas</h2>
                </div>
                
                <?php if (empty($notas)): ?>
                    <div class="p-6 text-center text-gray-500">
                        <p>No hay notas registradas para este cliente.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Folio</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Descripción</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Monto</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($notas as $nota): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($nota['folio']); ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        <div class="max-w-xs">
                                            <?php echo htmlspecialchars($nota['descripcion']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo formatCurrency($nota['total']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $nota['status'] === 'pagada' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                            <?php echo ucfirst($nota['status'] === 'pagada' ? 'pagado' : $nota['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo formatDate($nota['created_at']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <a href="pdf_nota_publico.php?token=<?php echo urlencode($token); ?>&nota_id=<?php echo $nota['id']; ?>" target="_blank" class="text-purple-600 hover:text-purple-900">Ver PDF</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Footer -->
            <div class="mt-8 text-center text-gray-500 text-sm">
                <p>Sistema de Gestión - Acceso por QR</p>
            </div>
        </div>
    </div>
</body>
</html>