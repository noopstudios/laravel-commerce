<?php

/*
 * Row locks on real MySQL/InnoDB, which SQLite compiles away.
 *
 * Skipped unless COMMERCE_TEST_MYSQL_DATABASE is set. It drops and recreates every table of that
 * database, so point it at a dedicated, empty one:
 *
 *   COMMERCE_TEST_MYSQL_DATABASE=laravel_commerce_test COMMERCE_TEST_MYSQL_HOST=127.0.0.1 \
 *   COMMERCE_TEST_MYSQL_PORT=3306 COMMERCE_TEST_MYSQL_USERNAME=root COMMERCE_TEST_MYSQL_PASSWORD= \
 *   vendor/bin/pest tests/Feature/LedgerMysqlTest.php
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Facades\Commerce;
use Noop\Commerce\Models\Order;
use Noop\Commerce\Models\Payment;

/**
 * An environment variable of the MySQL test setup (read with getenv: the package has no .env).
 */
function lmEnv(string $name, string $default = ''): string
{
    $value = getenv('COMMERCE_TEST_MYSQL_'.$name);

    return is_string($value) && $value !== '' ? $value : $default;
}

const LM_SKIP = 'Set COMMERCE_TEST_MYSQL_DATABASE (see the file header) to run the MySQL locking tests.';

beforeEach(function () {
    $database = lmEnv('DATABASE');

    if ($database === '') {
        return;
    }

    $connection = [
        'driver' => 'mysql',
        'host' => lmEnv('HOST', '127.0.0.1'),
        'port' => lmEnv('PORT', '3306'),
        'database' => $database,
        'username' => lmEnv('USERNAME', 'root'),
        'password' => lmEnv('PASSWORD'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ];
    // Two connections, so two concurrent transactions.
    config(['database.connections.mysql_t1' => $connection, 'database.connections.mysql_t2' => $connection]);
    DB::setDefaultConnection('mysql_t1');

    Schema::dropAllTables();
    /** @var callable(): void $createSettingsTable */
    $createSettingsTable = [require __DIR__.'/../../vendor/spatie/laravel-settings/database/migrations/create_settings_table.php.stub', 'up'];
    $createSettingsTable();
    /** @var list<string> $paths */
    $paths = app('migrator')->paths();
    Artisan::call('migrate', ['--database' => 'mysql_t1', '--realpath' => true, '--path' => [...$paths, realpath(__DIR__.'/../database/migrations')]]);
});

afterEach(function () {
    if (lmEnv('DATABASE') !== '') {
        DB::setDefaultConnection('testing');
    }
});

it('waits for the order row lock before recording a payment', function () {
    $order = Order::factory()->create(['billing_flow' => BillingFlow::InvoiceFirst]);
    $order->addItem(['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23]);
    $order->confirm();

    // Another transaction holds the order row.
    $t2 = DB::connection('mysql_t2');
    $t2->beginTransaction();
    $t2->table('orders')->where('id', $order->id)->lockForUpdate()->first();
    DB::connection('mysql_t1')->statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        expect(fn () => Commerce::recordPayment([[$order, 1230]], PaymentMethod::Transfer, now()))
            ->toThrow(QueryException::class, 'Lock wait timeout');
    } finally {
        $t2->rollBack();
    }

    expect(Payment::query()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment);

    // Once the lock is released, the same payment goes through.
    Commerce::recordPayment([[$order, 1230]], PaymentMethod::Transfer, now());

    expect($order->status)->toBe(OrderStatus::Paid);
})->skip(lmEnv('DATABASE') === '', LM_SKIP);
