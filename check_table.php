<?php
require_once 'functions.php';

try {
    $db = new Database();
    $stmt = $db->query('DESCRIBE nota_productos');
    echo "Estructura de la tabla nota_productos:\n";
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . ' - ' . $row['Type'] . ' - ' . $row['Null'] . ' - ' . $row['Key'] . ' - ' . $row['Default'] . ' - ' . $row['Extra'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>