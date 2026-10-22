<?php

echo "<h3>PDO</h3>";

if (class_exists('PDO')) {
    echo "PDO OK<br>";
} else {
    echo "PDO NÃO ENCONTRADO<br>";
}

echo "<h3>Drivers</h3>";

print_r(PDO::getAvailableDrivers());