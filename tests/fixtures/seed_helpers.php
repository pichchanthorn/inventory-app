<?php
declare(strict_types=1);

// ================================================
// Minimal seed helpers shared by the P0 test suite. Every function here
// inserts the smallest row a test needs and returns it as an array -
// nothing more elaborate than what the P0 scope actually requires.
// ================================================

// role_id: 1=Admin, 2=User, 3=Viewer (database/schema.sql seed data).
function testSeedUser(PDO $pdo, int $roleId, string $label = 'user'): array
{
    static $n = 0;
    $n++;
    $email = "{$label}{$n}." . bin2hex(random_bytes(4)) . '@test.local';
    $plainPassword = 'TestPass123!';
    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,?)');
    $stmt->execute(["Test " . ucfirst($label) . " {$n}", $email, $hash, $roleId]);
    $id = (int) $pdo->lastInsertId();

    return ['id' => $id, 'email' => $email, 'password' => $plainPassword, 'role_id' => $roleId];
}

function testSeedAdmin(PDO $pdo): array { return testSeedUser($pdo, 1, 'admin'); }
function testSeedUserRole(PDO $pdo): array { return testSeedUser($pdo, 2, 'staff'); }
function testSeedViewer(PDO $pdo): array { return testSeedUser($pdo, 3, 'viewer'); }

// $stock: starting current_stock. $overrides: any products column to set
// explicitly (e.g. ['cost_price' => 5]).
function testSeedProduct(PDO $pdo, int $stock = 100, array $overrides = []): array
{
    static $n = 0;
    $n++;
    $sku = 'TST-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT) . '-' . bin2hex(random_bytes(3));

    $row = array_merge([
        'name' => "Test Product {$n}",
        'sku' => $sku,
        'cost_price' => 10.00,
        'sale_price' => 15.00,
        'current_stock' => $stock,
    ], $overrides);

    $columns = array_keys($row);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $stmt = $pdo->prepare('INSERT INTO products (' . implode(',', $columns) . ') VALUES (' . $placeholders . ')');
    $stmt->execute(array_values($row));
    $row['id'] = (int) $pdo->lastInsertId();

    return $row;
}

function testSeedCustomer(PDO $pdo, string $name = 'Test Customer'): array
{
    $stmt = $pdo->prepare('INSERT INTO customers (name, phone) VALUES (?, ?)');
    $stmt->execute([$name, '0000000000']);
    return ['id' => (int) $pdo->lastInsertId(), 'name' => $name];
}

function testRandomToken(): string
{
    return bin2hex(random_bytes(32));
}
