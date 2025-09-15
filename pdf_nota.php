<?php
require_once 'functions.php';
require_once 'lib/PDF.php';
requireLogin();

$db = new Database();

// Verificar que se proporcione un ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: nota.php?error=ID de nota inválido');
    exit();
}

$notaId = intval($_GET['id']);

try {
    // Obtener datos de la nota
    $stmt = $db->prepare("
        SELECT n.*, c.nombre as cliente_nombre, c.email as cliente_email, 
               c.telefono as cliente_telefono, c.direccion as cliente_direccion,
               c.rfc as cliente_rfc, c.logo_cliente
        FROM notas n 
        LEFT JOIN clientes c ON n.cliente_id = c.id 
        WHERE n.id = ?
    ");
    $stmt->execute([$notaId]);
    $nota = $stmt->fetch();
    
    if (!$nota) {
        header('Location: nota.php?error=Nota no encontrada');
        exit();
    }
    
    // Verificar permisos
    if (!hasPermission('view_notes') && $nota['created_by'] !== $_SESSION['user_id']) {
        header('Location: nota.php?error=Sin permisos para ver esta nota');
        exit();
    }
    
    // Preparar datos del cliente
    $cliente = [
        'nombre' => $nota['cliente_nombre'] ?? 'Cliente eliminado',
        'email' => $nota['cliente_email'] ?? '',
        'telefono' => $nota['cliente_telefono'] ?? '',
        'direccion' => $nota['cliente_direccion'] ?? '',
        'rfc' => $nota['cliente_rfc'] ?? '',
        'logo_cliente' => $nota['logo_cliente'] ?? null
    ];
    
    // Crear el PDF
    $pdf = new PDF();
    $pdf->generarNotaPDF($nota, $cliente);
    
    // Registrar la acción
    logAction($_SESSION['user_id'], 'generate_pdf', 'notas', $notaId);
    
    // Enviar el PDF al navegador
    $filename = 'nota_' . $nota['folio'] . '_' . date('Y-m-d') . '.pdf';
    $pdf->Output('D', $filename);
    
} catch (Exception $e) {
    error_log('Error generando PDF: ' . $e->getMessage());
    header('Location: nota.php?error=Error al generar el PDF');
    exit();
}
?>