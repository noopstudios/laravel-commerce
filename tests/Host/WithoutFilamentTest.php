<?php

use Filament\FilamentServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Facades\Commerce;
use Noop\Commerce\Models\Order;
use Noop\Commerce\Policies\OrderPolicy;
use Noop\Commerce\Tests\Host\WithoutFilamentTestCase;
use Noop\Eupago\EupagoServiceProvider;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Models\InvoiceDocument;
use Symfony\Component\Finder\Finder;

uses(WithoutFilamentTestCase::class);

it('confirms, records a payment and issues its document with neither Filament nor EuPago registered', function () {
    expect(app()->getProviders(FilamentServiceProvider::class))->toBe([])
        ->and(app()->getProviders(LivewireServiceProvider::class))->toBe([])
        ->and(app()->getProviders(EupagoServiceProvider::class))->toBe([])
        ->and(Schema::hasTable('payment_attempts'))->toBeFalse();

    Http::preventStrayRequests();
    Moloni::fake();
    $order = Order::factory()->create(['billing_vat' => '509999999', 'is_business' => true]);
    $order->addItem(['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23]);
    $order->confirm();

    Commerce::recordPayment([[$order, 1230]], PaymentMethod::Cash, now());

    $document = InvoiceDocument::query()->sole();
    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($document->type)->toBe(DocumentType::InvoiceReceipt)
        ->and($document->status)->toBe(DocumentStatus::Issued)
        ->and(Gate::getPolicyFor(Order::class))->toBeInstanceOf(OrderPolicy::class);
});

it('uses Filament only in the Filament plugin, so the package works without it', function () {
    $offending = [];

    foreach (Finder::create()->files()->name('*.php')->in(__DIR__.'/../../src')->exclude('Filament') as $file) {
        if (preg_match('/\b(Filament|Livewire|BladeUI)\\\\/', $file->getContents()) === 1) {
            $offending[] = $file->getRelativePathname();
        }
    }

    sort($offending);

    expect($offending)->toBe([]);
});
