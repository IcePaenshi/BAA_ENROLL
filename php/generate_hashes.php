<?php
echo "Cashier password hash: " . password_hash('cashier123', PASSWORD_DEFAULT) . "<br>";
echo "Registrar password hash: " . password_hash('registrar123', PASSWORD_DEFAULT) . "<br>";
?>