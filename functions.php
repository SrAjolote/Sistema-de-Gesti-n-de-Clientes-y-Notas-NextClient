<?php
require_once 'db.php';

// Iniciar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Verificar si el usuario está autenticado
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Verificar rol del usuario
 */
function hasRole($role) {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === $role;
}

/**
 * Verificar si el usuario tiene permisos
 */
function hasPermission($permission) {
    if (!isLoggedIn()) return false;
    
    $role = $_SESSION['user_role'];
    
    switch ($permission) {
        case 'admin_full':
            return $role === 'admin';
        case 'create_notes':
            return in_array($role, ['admin', 'secretario']);
        case 'delete_records':
            return $role === 'admin';
        case 'view_logs':
            return $role === 'admin';
        case 'generate_qr':
            return in_array($role, ['admin', 'secretario']);
        default:
            return false;
    }
}

/**
 * Redirigir si no tiene permisos
 */
function requirePermission($permission) {
    if (!hasPermission($permission)) {
        header('Location: index.php?error=sin_permisos');
        exit();
    }
}

/**
 * Redirigir si no está logueado
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit();
    }
}

/**
 * Autenticar usuario
 */
function authenticateUser($email, $password) {
    $db = new Database();
    $stmt = $db->prepare("SELECT id, nombre, email, password, rol FROM usuarios WHERE email = ? AND activo = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['nombre'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['rol'];
        
        // Registrar login en logs
        logAction($user['id'], 'login', 'usuarios', $user['id']);
        
        return true;
    }
    
    return false;
}

/**
 * Cerrar sesión
 */
function logout() {
    if (isLoggedIn()) {
        logAction($_SESSION['user_id'], 'logout', 'usuarios', $_SESSION['user_id']);
    }
    
    session_destroy();
    header('Location: index.php');
    exit();
}

/**
 * Registrar acción en logs
 */
function logAction($usuario_id, $accion, $tabla, $registro_id = null) {
    try {
        $db = new Database();
        $stmt = $db->prepare("INSERT INTO logs (usuario_id, accion, tabla, registro_id, fecha) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$usuario_id, $accion, $tabla, $registro_id]);
    } catch (Exception $e) {
        // Log error but don't break the application
        error_log("Error logging action: " . $e->getMessage());
    }
}

/**
 * Generar token único para QR
 */
function generateQRToken() {
    return bin2hex(random_bytes(16));
}

/**
 * Generar folio consecutivo
 */
function generateFolio($db = null) {
    if ($db === null) {
        $db = new Database();
    }
    $stmt = $db->query("SELECT MAX(CAST(folio AS UNSIGNED)) as max_folio FROM notas");
    $result = $stmt->fetch();
    
    $nextFolio = ($result['max_folio'] ?? 0) + 1;
    return str_pad($nextFolio, 6, '0', STR_PAD_LEFT);
}

/**
 * Sanitizar entrada
 */
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Validar email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Formatear moneda
 */
function formatCurrency($amount) {
    return '$' . number_format($amount, 2, '.', ',');
}

/**
 * Formatear fecha
 */
function formatDate($date) {
    return date('d/m/Y H:i', strtotime($date));
}

/**
 * Respuesta JSON
 */
function jsonResponse($success, $message = '', $data = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

/**
 * Subir archivo
 */
function uploadFile($file, $directory = 'uploads/', $allowedTypes = ['jpg', 'jpeg', 'png', 'gif']) {
    if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
        return false;
    }
    
    $fileName = $file['name'];
    $fileTmp = $file['tmp_name'];
    $fileSize = $file['size'];
    $fileError = $file['error'];
    
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    if ($fileError !== 0) {
        return false;
    }
    
    if (!in_array($fileExt, $allowedTypes)) {
        return false;
    }
    
    if ($fileSize > 5000000) { // 5MB max
        return false;
    }
    
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    
    $newFileName = uniqid('', true) . '.' . $fileExt;
    $fileDestination = $directory . $newFileName;
    
    if (move_uploaded_file($fileTmp, $fileDestination)) {
        return $fileDestination;
    }
    
    return false;
}
?>