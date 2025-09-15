<?php
// Habilitar reporte de errores detallado
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

try {
    require_once 'functions.php';
    require_once 'lib/PDF.php';
} catch (Exception $e) {
    die("Error cargando archivos: " . $e->getMessage());
}

// Verificar que se proporcionen los parámetros necesarios
if (!isset($_GET['token']) || empty($_GET['token']) || !isset($_GET['nota_id']) || !is_numeric($_GET['nota_id'])) {
    http_response_code(404);
    die('Parámetros no válidos');
}

$token = $_GET['token'];
$notaId = intval($_GET['nota_id']);

try {
    $db = new Database();
    
    // Verificar que el token sea válido y obtener el cliente
    $stmt = $db->prepare("SELECT * FROM clientes WHERE qr_token = ?");
    $stmt->execute([$token]);
    $cliente = $stmt->fetch();
    
    if (!$cliente) {
        http_response_code(404);
        die('Token no válido');
    }
    
    // Obtener datos de la nota y verificar que pertenezca al cliente
    $stmt = $db->prepare("
        SELECT n.*, c.nombre as cliente_nombre, c.email as cliente_email, 
               c.telefono as cliente_telefono, c.direccion as cliente_direccion,
               c.rfc as cliente_rfc, c.logo_cliente
        FROM notas n 
        LEFT JOIN clientes c ON n.cliente_id = c.id 
        WHERE n.id = ? AND n.cliente_id = ?
    ");
    $stmt->execute([$notaId, $cliente['id']]);
    $nota = $stmt->fetch();
    
    if (!$nota) {
        http_response_code(404);
        die('Nota no encontrada o no pertenece a este cliente');
    }
    
    // Preparar datos del cliente
    $clienteData = [
        'nombre' => $nota['cliente_nombre'] ?? 'Cliente eliminado',
        'email' => $nota['cliente_email'] ?? '',
        'telefono' => $nota['cliente_telefono'] ?? '',
        'direccion' => $nota['cliente_direccion'] ?? '',
        'rfc' => $nota['cliente_rfc'] ?? '',
        'logo_url' => $nota['logo_cliente'] ?? null
    ];
    
    // Crear el PDF
    $pdf = new PDF();
    $pdf->generarNotaPDF($nota, $clienteData);
    
    // Enviar el PDF al navegador
    $filename = 'nota_' . $nota['folio'] . '_' . date('Y-m-d') . '.pdf';
    $pdf->Output('I', $filename); // 'I' para mostrar en el navegador, 'D' para descargar
    
} catch (Exception $e) {
    error_log('Error en pdf_nota_publico.php: ' . $e->getMessage());
    http_response_code(500);
    die('Error interno del servidor');
}
?>