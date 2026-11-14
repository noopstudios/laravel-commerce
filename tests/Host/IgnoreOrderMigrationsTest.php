<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Noop\Commerce\Billing;
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Facades\Commerce;
use Noop\Commerce\Tests\Fixtures\Booking;
use Noop\Commerce\Tests\Host\IgnoreOrderMigrationsTestCase;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Models\InvoiceDocument;

uses(IgnoreOrderMigrationsTestCase::class);

beforeEach(function () {
    // The host's own table.
    Schema::create('bookings', function (Blueprint $table) {
        $table->id();
        $table->string('status');
        $table->timestamps();
    });
});

it('leaves the orders tables to the host but still creates the payments tables', function () {
    expect(Schema::hasTable('orders'))->toBeFalse()
        ->and(Schema::hasTable('order_items'))->toBeFalse()
        ->and(Schema::hasTable('payments'))->toBeTrue()
        ->and(Schema::hasTable('payment_allocations'))->toBeTrue();
});

it('issues the invoice-receipt of a host payable, and recovers a missing one, without the orders table', function () {
    $moloni = Moloni::fake();
    $booking = Booking::query()->create();
    $missed = Booking::query()->create();
    $invoiceReceipt = fn (Booking $booking) => InvoiceDocument::query()->where('type', DocumentType::InvoiceReceipt)
        ->where('invoiceable_type', $booking->getMorphClass())->where('invoiceable_id', $booking->id)->sole();

    Commerce::recordPayment([[$booking, 1230]], PaymentMethod::Cash, now());

    expect($booking->status)->toBe('paid')
        ->and($invoiceReceipt($booking)->status)->toBe(DocumentStatus::Issued)
        ->and($invoiceReceipt($booking)->expected_total_cents)->toBe(1230);

    // The process dies before the after-commit sync of the second booking.
    app()->instance(Billing::class, Mockery::spy(Billing::class));
    Commerce::recordPayment([[$missed, 1230]], PaymentMethod::Cash, now());
    app()->forgetInstance(Billing::class);

    expect(Artisan::call('commerce:recover'))->toBe(0);

    expect($invoiceReceipt($missed)->status)->toBe(DocumentStatus::Issued);
    $moloni->assertIssuedCount(2);
});
