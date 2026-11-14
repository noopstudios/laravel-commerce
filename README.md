# noopstudios/laravel-commerce

Encomendas, pagamentos e imputações para projetos Laravel 13 novos. Liga o [`noopstudios/laravel-eupago`](https://github.com/noopstudios/laravel-eupago) (opcional: Multibanco e MB WAY) ao [`noopstudios/laravel-moloni`](https://github.com/noopstudios/laravel-moloni) (obrigatório: documentos fiscais): uma encomenda paga ou faturada acaba com os documentos certos no Moloni, sem que o pagamento dependa deles.

Requisitos: PHP `^8.3`, Laravel 13. Filament v5 é opcional (só para o painel). Licença: MIT.

## O que faz

- **Encomendas** com linhas, totais calculados como o Moloni os calcula e um estado que só o pacote escreve.
- **Pagamentos e imputações.** Um pagamento é dinheiro recebido; as imputações dizem o que ele pagou. A dívida de uma encomenda é o total menos as imputações de pagamentos não anulados e menos o que notas de crédito vivas abatem às suas faturas.
- **Dois circuitos de faturação** (ver [Os dois circuitos](#os-dois-circuitos)): pagar primeiro (fatura-recibo) ou faturar primeiro (fatura e um recibo por pagamento).
- **Fila de revisão** para o dinheiro que não se pode aplicar sozinho (tardio, duplicado, a mais, moeda errada): fica registado, sem imputação, à espera de uma decisão humana.
- **Plugin Filament** opcional para operar tudo isto.

As tabelas são `orders`, `order_items`, `payments` e `payment_allocations`.

## Instalação

```bash
composer require noopstudios/laravel-commerce
php artisan migrate   # as migrações são carregadas pelo pacote
php artisan vendor:publish --tag=commerce-config   # opcional
```

Enquanto os três pacotes não estiverem publicados no Packagist, o projeto tem de os declarar em `repositories` (os `repositories` de uma dependência são ignorados). Com repositórios sem tag, pede-se a versão `@dev`:

```json
{
    "repositories": [
        {"type": "path", "url": "../laravel-moloni"},
        {"type": "path", "url": "../laravel-eupago"},
        {"type": "path", "url": "../laravel-commerce"}
    ]
}
```

```bash
composer require noopstudios/laravel-commerce:@dev noopstudios/laravel-moloni:@dev noopstudios/laravel-eupago:@dev
```

(com `vcs`, trocar `"type": "path"` por `"type": "vcs"` e o `url` pelo do repositório). O `laravel-eupago` só é preciso para Multibanco e MB WAY; o Filament, só para o painel.

```dotenv
COMMERCE_ORDER_PREFIX=ORD   # prefixo do número da encomenda: ORD-<id>
```

Opções só em `config/commerce.php`: `models.order` (ver [Estender](#2-estender-subclasse-de-order)) e `permissions` (ver [Plugin Filament](#plugin-filament)).

### O que a aplicação tem de ter

O que vem dos pacotes irmãos não se repete aqui; ver os respetivos README.

- **laravel-moloni:** as credenciais e as definições gravadas e validadas (`php artisan moloni:check`), uma fila `database` com worker, e cache com locks partilhada. Nas definições do Moloni, em **métodos de pagamento**, mapeia as quatro chaves que este pacote usa: `multibanco`, `mbway`, `transfer` e `cash`. Para o circuito B é preciso uma **data de vencimento** configurada para cada `payment_terms_days` que usares (o prazo `0` incluído).
- **laravel-eupago** (se o instalares): a chave da API, o webhook configurado no EuPago e o scheduler a correr. O pacote liga-se sozinho: regista o seu `HandlesEupagoPayments` (ver [Limites](#limites-e-notas-de-operação) se tiveres o teu).
- **Scheduler, fila e cache:** o pacote agenda `commerce:recover` de cinco em cinco minutos, por isso o `schedule:run` tem de estar ativo e o worker da fila do Moloni a correr. Uma cache `array` ou `file` não serve com mais de um processo.

## Adaptar a encomenda: três níveis

| Nível | Quando | O que fazes |
| --- | --- | --- |
| 1. Usar | A encomenda do pacote chega | Nada. Os extras vão para `meta` (JSON). |
| 2. Estender | Precisas de colunas ou métodos teus | Subclasse de `Order`, `commerce.models.order` e uma migração tua. |
| 3. Substituir | Já tens um modelo que se paga (reserva, inscrição) | `Commerce::ignoreOrderMigrations()` e implementar `Payable` e `Invoiceable` no teu modelo. |

### 1. Usar

```php
use Noop\Commerce\Models\Order;

$order = Order::create([
    'billing_name' => 'Ana Silva',
    'billing_vat' => '509999999',
    'is_business' => true,
    'meta' => ['campaign' => 'black-friday'],
]);
```

`meta` (encomenda e linhas) é um array JSON livre.

### 2. Estender (subclasse de `Order`)

```php
namespace App\Models;

use Noop\Commerce\Models\Order as BaseOrder;

class Order extends BaseOrder
{
    public function __construct(array $attributes = [])
    {
        $this->mergeFillable(['channel']);

        parent::__construct($attributes);
    }
}
```

```php
// config/commerce.php (publicado): definido antes de o pacote arrancar
'models' => ['order' => \App\Models\Order::class],
```

Acrescenta as colunas numa migração tua (`Schema::table('orders', ...)`; a tabela continua a ser `orders`). O alias morph continua a ser `order`: o `getMorphClass()` do pacote é fixo, por isso os documentos do Moloni e as imputações não dependem do nome da tua classe.

- **Campos congelados.** `billing_flow`, `currency` e `payment_terms_days` só mudam enquanto a encomenda é `draft`; depois lança `CommerceException`. As linhas também só mudam em `draft`. `status`, `paid_at`, `number`, `public_token` e os totais não são `fillable`: só o pacote os escreve.
- **`customerKey()`.** É o id estável do cliente no Moloni (o número do cliente é construído a partir dele). Por omissão, uma encomenda com comprador (`buyer`) usa a inicial do tipo do comprador mais o seu id (`U15`) e uma encomenda sem comprador usa `0` mais o id da encomenda. Se dois tipos de comprador partilharem a inicial (`Customer` e `Company`), sobrepõe o método:

```php
public function customerKey(): string
{
    return $this->buyer_type === null
        ? parent::customerKey()
        : strtoupper(substr(class_basename($this->buyer_type), 0, 2)).$this->buyer_id;   // duas letras em vez de uma
}
```

### 3. Substituir

Quando as tabelas e o modelo já são teus, diz ao pacote para não criar `orders` nem `order_items`, num `register()` ou `boot()` de um service provider teu:

```php
use Noop\Commerce\Facades\Commerce;

Commerce::ignoreOrderMigrations();
```

As tabelas `payments` e `payment_allocations` carregam sempre. O teu modelo implementa `Noop\Commerce\Contracts\Payable` e `Noop\Moloni\Contracts\Invoiceable` (ver o README do `laravel-moloni`: `toInvoiceData()`, `invoiceReference()`, `customerKey()`) e tem chave primária inteira.

```php
use Illuminate\Database\Eloquent\Model;
use Noop\Commerce\Contracts\Payable;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Moloni\Contracts\Invoiceable;

class Booking extends Model implements Invoiceable, Payable
{
    public function totalCents(): int { return $this->total_cents; }

    public function currency(): string { return 'EUR'; }

    public function billingFlow(): BillingFlow { return BillingFlow::PayFirst; }

    public function canReceivePayment(): bool { return $this->status === 'awaiting_payment'; }

    public function onDueChanged(int $dueCents): void
    {
        $this->forceFill(['status' => $dueCents === 0 ? 'paid' : 'awaiting_payment'])->save();
    }

    public function onExpired(): void
    {
        $this->forceFill(['status' => 'expired'])->save();
    }

    public function paymentLabel(): string { return "Reserva {$this->id}"; }

    // toInvoiceData(), invoiceReference() e customerKey(): ver o README do laravel-moloni.
}
```

Cada método de `Payable` e quando o pacote o chama:

| Método | Quando |
| --- | --- |
| `totalCents()` | Sempre que calcula a dívida. Um total `0` não gera documentos. |
| `currency()` | Ao criar um pagamento manual (fica no pagamento) e ao pedir Multibanco/MB WAY, que só cobram `EUR`. |
| `billingFlow()` | Decide o circuito: `PayFirst` (A) ou `InvoiceFirst` (B). |
| `canReceivePayment()` | Antes de um pagamento manual, antes de pedir Multibanco/MB WAY, e quando o EuPago confirma um pagamento: `false` manda o pagamento para revisão (`not_accepting_payments`). |
| `onDueChanged(int $dueCents)` | **Dentro da transação, com a linha do payable bloqueada**, depois de cada imputação e de cada anulação de pagamento. É aqui que o teu modelo atualiza o estado. Quando a dívida chega a zero o pacote dispara `PayableSettled`. |
| `onExpired()` | Dentro da transação do EuPago, quando uma referência Multibanco expirada era a última tentativa em aberto (ver [Validade](#validade-das-referências)). Decide se fecha o modelo; se fechar, dispara o `PayableExpired` tu. |
| `paymentLabel()` | Só nas mensagens de erro e no painel. |

Para o morph do teu modelo vale o que o README do `laravel-moloni` diz sobre o morph map: define-o antes do primeiro documento e não o mudes.

O pacote não pede a fatura do circuito B de um payable que não seja a sua `Order`: o teu modelo pede-a quando quiser, com `Moloni::invoice($model, $payment_terms_days)`, e o pacote trata dos recibos. No circuito A o pacote emite a fatura-recibo sozinho quando o payable fica pago. O plugin Filament foi pensado para o `Order` do pacote (níveis 1 e 2).

## Criar uma encomenda

```php
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Models\Order;

$order = Order::create([
    'billing_name' => 'Ana Silva',
    'billing_vat' => '509999999',          // sem NIF: consumidor final
    'billing_email' => 'ana@example.com',
    'billing_country' => 'PT',
    'is_business' => true,
    'billing_flow' => BillingFlow::PayFirst,   // por omissão
    'payment_terms_days' => 0,                 // circuito B: dias até ao vencimento da fatura
    'buyer_type' => User::class,               // opcional: o comprador
    'buyer_id' => $user->id,
]);

$order->addItem([
    'name' => 'Consultoria',
    'reference' => 'CONS-1',
    'unit_net_price' => '10.00',   // sem IVA, até 5 casas decimais
    'tax_rate' => 23,
    'quantity' => 2,               // opcional; 1 por omissão
]);

$order->confirm();
```

- **Linhas.** `addItem()` aceita um array com os atributos da linha (`name`, `unit_net_price`, `tax_rate`, `quantity`, `reference`, `description`, `exemption_reason`, `moloni_product_id`, `meta`) ou um `Purchasable`. A quantidade é o argumento, senão a chave `quantity` do item, senão 1. Só se acrescentam linhas a uma encomenda `draft`.

```php
use Illuminate\Database\Eloquent\Model;
use Noop\Commerce\Contracts\Purchasable;

class Workshop extends Model implements Purchasable
{
    public function toOrderItem(): array
    {
        return [
            'name' => $this->title,
            'reference' => "WS-{$this->id}",
            'unit_net_price' => number_format($this->price_cents / 100, 5, '.', ''),
            'tax_rate' => 23,
        ];
    }
}

$order->addItem($workshop, 2);   // a linha fica ligada ao workshop (relação `purchasable`)
```

- **Totais.** São calculados a partir das linhas de fatura, linha a linha, como o Moloni os calcula (arredondamento half-up a duas casas; ver "Valores e totais" no README do `laravel-moloni`), por isso o valor cobrado é o valor faturado. `subtotal_cents`, `tax_cents` e `total_cents` atualizam-se a cada linha. Uma linha sem IVA precisa de `exemption_reason`.
- **`confirm()`** fecha o rascunho: passa a `pending_payment`, ou a `paid` logo (com `PayableSettled`, e sem documentos) quando o total é zero. Falha se não houver linhas. No circuito B pede ainda a fatura ao Moloni, depois do commit e sem nunca falhar por causa disso.
- **`cancel()`** só se a encomenda for `draft`, `pending_payment` ou `expired` e não tiver pagamentos imputados. Não toca nos documentos do Moloni nem nas referências já pedidas: um pagamento que chegue depois vai para a fila de revisão.
- **Número.** `PREFIX-<id>` (`COMMERCE_ORDER_PREFIX`). Há também um `public_token` (ULID) para URLs públicos.

### Estados

Só o pacote escreve o estado (`status` não é `fillable`).

| Estado | Quando |
| --- | --- |
| `draft` | Ao criar; as linhas ainda mudam. |
| `pending_payment` | `confirm()` com total > 0; também quando a anulação de um pagamento devolve a dívida toda. |
| `partially_paid` | Depois de uma imputação, ou de uma anulação, que deixa a dívida entre zero e o total. |
| `paid` | A dívida chegou a zero (ou `confirm()` de um total zero). Guarda `paid_at`. |
| `expired` | Circuito A: a última referência Multibanco em aberto expirou sem pagamento. |
| `cancelled` | `cancel()`. |

Uma imputação a uma encomenda `expired` ou `cancelled` (decisão de um operador, ver [`allocate`](#fila-de-revisão)) reabre-a.

## Os dois circuitos

| | A. Pagar primeiro | B. Faturar primeiro |
| --- | --- | --- |
| `billing_flow` | `PayFirst` (por omissão) | `InvoiceFirst` |
| Documento | Fatura-recibo, quando está paga | Fatura no `confirm()`, e um recibo por pagamento |
| Valor de cada pagamento | A dívida exata, num só pagamento | Qualquer valor até à dívida |
| Vários pagamentos | Não | Sim, e um pagamento pode pagar várias encomendas |
| Expira | Sim, com a última referência Multibanco | Não: a dívida mantém-se |

**A. Pagar primeiro.** `confirm()` → pedes a referência (ou registas o pagamento) pelo valor exato da dívida → o pagamento é imputado e a encomenda fica `paid` → o pacote pede ao Moloni a fatura-recibo, com o método e a data do pagamento. Um valor diferente da dívida é recusado ao pedir, e um pagamento do EuPago de outro valor vai para revisão (`amount_mismatch`).

```php
use Noop\Commerce\Facades\Commerce;
use Noop\Commerce\Models\Order;

$order = Order::create(['billing_vat' => '509999999', 'is_business' => true]);
$order->addItem(['name' => 'Consultoria', 'unit_net_price' => '10', 'tax_rate' => 23]);
$order->confirm();                                  // 12,30 € a pagar

$attempt = Commerce::multibanco($order);            // entidade e referência para o cliente
// o EuPago confirma → encomenda paga → fatura-recibo pedida ao Moloni
```

**B. Faturar primeiro.** `confirm()` pede a fatura, com o vencimento de `payment_terms_days` → cada pagamento (parcial ou total) é imputado → o pacote pede um recibo por pagamento, **assim que a fatura estiver emitida** (um pagamento recebido antes disso só recebe o recibo quando o Moloni emitir a fatura). Um pagamento que pague várias faturas tem um só recibo.

```php
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Facades\Commerce;
use Noop\Commerce\Models\Order;

$order = Order::create([
    'billing_flow' => BillingFlow::InvoiceFirst,
    'payment_terms_days' => 30,
    'billing_vat' => '509999999',
    'is_business' => true,
]);
$order->addItem(['name' => 'Projeto', 'unit_net_price' => '1000', 'tax_rate' => 23]);
$order->confirm();                                  // fatura a 30 dias pedida ao Moloni

Commerce::multibanco($order, 50000);                // 500,00 € agora; o resto mais tarde
Commerce::recordPayment([[$order, 73000]], PaymentMethod::Transfer, now());
```

### O pagamento não depende da faturação

Nada do que pede documentos corre dentro da transação de um pagamento, e nenhuma exceção da faturação chega a quem pagou. Se o Moloni falhar:

- a encomenda continua `paid` e o `PayableSettled` já foi disparado;
- o documento fica `failed` (ou `unknown`, ver o README do `laravel-moloni`) e **os caminhos automáticos nunca o repetem**;
- no painel aparece "Documento em falta" e a ação **Emitir documentos** (`Billing::sync($order, retryFailed: true)`) volta a pedir tudo o que falta, falhados incluídos: uma fatura ou fatura-recibo nova com os dados atuais da encomenda, um recibo falhado reenviado, e um recibo anulado no Moloni substituído por um novo.

`commerce:recover` corre de cinco em cinco minutos e, para as encomendas `pending_payment`, `partially_paid` ou `paid` mexidas nas últimas 24 horas e para os payables com imputações das últimas 24 horas, pede o que falta e nunca existiu: a fatura do circuito B, a fatura-recibo do circuito A, os recibos. Apanha o processo que morreu entre o commit e o pedido, e tenta outra vez o pedido que o Moloni recusou antes de existir documento. Não repete documentos falhados. Também se corre à mão: `php artisan commerce:recover`.

## Pagamentos

### Multibanco e MB WAY

```php
use Noop\Commerce\Facades\Commerce;

$attempt = Commerce::multibanco($order);                  // a dívida toda
$attempt = Commerce::multibanco($order, 50000);           // circuito B: uma parte
$attempt = Commerce::mbway($order, '912345678');
```

Devolvem a tentativa do `laravel-eupago` (`PaymentAttempt`, com `entity`, `reference`, `amount_cents`, `expires_at`). Regras:

- Exigem o `laravel-eupago` instalado, a encomenda em `EUR` e a aceitar pagamentos (`pending_payment` ou `partially_paid`).
- Sem valor, pede-se a dívida. No circuito A só se aceita a dívida exata; no B, mais de zero e até à dívida.
- **Nunca dentro de uma transação de base de dados** (o `laravel-eupago` recusa).
- Os erros são `Noop\Eupago\Exceptions\EupagoException` com `->type`: `Rejected` (podes tentar outra vez), `Unknown` (resultado incerto: não repitas, a encomenda fica bloqueada até o webhook, a expiração ou um operador a resolverem) e `Blocked` (pedido em curso, tentativa por resolver ou limite atingido). O detalhe está no README do `laravel-eupago`.
- Quando o EuPago confirma, o pacote regista o pagamento e imputa-o (ver [Fila de revisão](#fila-de-revisão) para o que não se pode imputar).

### Pagamento manual

```php
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Facades\Commerce;

$payment = Commerce::recordPayment(
    allocations: [[$order, 73000]],            // [payable, cêntimos]
    method: PaymentMethod::Transfer,           // Multibanco, Mbway, Transfer ou Cash
    paidAt: now(),
    recordedBy: $user,                         // opcional; só se guarda o id
    notes: 'Transferência de 12/11',
    idempotencyKey: 'transfer-2026-11-12-001',
);
```

O pagamento é imputado de imediato, na mesma transação, e a encomenda atualiza-se. Cada valor tem de ser maior que zero e caber na dívida; no circuito A é a dívida exata. A encomenda tem de aceitar pagamentos.

**Chave de idempotência.** Sem chave, cada chamada é um pagamento novo (gera-se um ULID). Com chave, repetir a chamada devolve o pagamento já registado, desde que seja manual, não esteja anulado e tenha o mesmo total; qualquer outro caso lança `CommerceException`. Duas chamadas simultâneas com a mesma chave registam um só pagamento.

### Um recibo para várias encomendas

Um pagamento pode pagar várias encomendas do circuito B, com um só recibo no Moloni. As regras, verificadas antes de gravar nada:

- todas em circuito B (o circuito A não partilha pagamentos);
- cada uma com a **fatura já emitida**;
- todas faturadas ao **mesmo cliente do Moloni**;
- nenhuma faturada a consumidor final (sem NIF);
- no máximo 25 payables por pagamento.

```php
Commerce::recordPayment(
    allocations: [
        [$orderA, 123_00],      // liquida a encomenda
        [$orderB, 50_00],       // parcial
    ],
    method: PaymentMethod::Transfer,
    paidAt: now(),
    idempotencyKey: 'transfer-2026-11-12-002',
);
```

Se alguma regra falhar, nada é gravado e a exceção diz qual. O recibo é pedido quando todas as faturas estão emitidas.

### Dívida

```php
Commerce::dueCents($order);   // int, cêntimos
```

É o total menos as imputações de pagamentos **não anulados**, menos o que as notas de crédito **vivas** (pendentes, a emitir, emitidas ou por confirmar) abatem às faturas vivas do payable, nunca abaixo de zero. É uma leitura simples, para mostrar.

### Fila de revisão

Quando o EuPago confirma um pagamento que não se pode imputar à encomenda inteira, o pacote **regista o pagamento sem imputação**, com `needs_review = true` e um `review_reason`, dispara `PaymentNeedsReview` e deixa a encomenda como estava. O webhook fica processado: não há reenvios.

| `review_reason` | Quando |
| --- | --- |
| `not_accepting_payments` | A encomenda já não aceita pagamentos: tardio (`expired`, `cancelled`, ainda `draft`) ou duplicado (já `paid`). |
| `exceeds_due` | Circuito B: valor acima da dívida de agora (excesso). |
| `amount_mismatch` | Circuito A: valor diferente da dívida. |
| `currency_mismatch` | Encomenda noutra moeda que não `EUR`. |
| `duplicate_transaction` | O identificador de transação do EuPago já estava registado noutro pagamento. |

Quem decide:

```php
// Aplicar a uma encomenda, uma só vez; reabre uma encomenda expirada ou cancelada
$allocation = Commerce::allocate($payment, $order);          // até ao que o pagamento tem e a encomenda deve
$allocation = Commerce::allocate($payment, $order, 5000);    // ou um valor

// Tirar da fila sem aplicar (reembolsado, tratado fora)
Commerce::resolveReview($payment, 'Reembolsado por transferência a 14/11');
```

- `allocate()` aplica o pagamento a **uma** encomenda, **uma só vez**: recusa se o pagamento já não está em revisão, está anulado, já foi imputado, ou a encomenda é `draft`; no circuito A tem de ser pela dívida toda. Se o valor imputado for menos que o pagamento, o resto continua em revisão até `resolveReview()`.
- `resolveReview()` acrescenta a nota ao pagamento e tira-o da fila.

### Anular um pagamento

```php
Commerce::voidPayment($payment, 'Enganei-me no valor', $user);
```

Só pagamentos **manuais**. As encomendas que ele pagou voltam a dever esse valor (e o estado atualiza-se: `pending_payment` ou `partially_paid`). É recusado enquanto existir um recibo vivo desse pagamento ou, no circuito A, uma fatura-recibo viva da encomenda: cancela primeiro o documento no Moloni. Os documentos nunca se tocam por ele.

### Validade das referências

Só uma referência **Multibanco expirada** que fosse a **última tentativa em aberto** fecha uma encomenda do circuito A que ainda esteja `pending_payment` (passa a `expired` e dispara `PayableExpired`). Um pedido MB WAY que expira ou é recusado nunca fecha nada: o cliente pode pedir outro pagamento. No circuito B nada expira. Uma encomenda `expired` já não aceita novos pedidos; um pagamento tardio vai para revisão e pode ser aplicado com `allocate()`.

## Eventos

Todos despachados depois do commit (`ShouldDispatchAfterCommit`), com a propriedade pública indicada:

| Evento | Propriedade | Quando |
| --- | --- | --- |
| `Noop\Commerce\Events\PayableSettled` | `payable` | A dívida chegou a zero (também uma encomenda de total zero ao confirmar). Pode voltar a disparar se um pagamento for anulado e a encomenda for paga outra vez: o listener deve ser idempotente. |
| `Noop\Commerce\Events\PayableExpired` | `payable` | Uma encomenda do circuito A expirou. |
| `Noop\Commerce\Events\PaymentNeedsReview` | `payment` | Um pagamento foi para a fila de revisão. |

Exemplo, entregar o produto quando a encomenda está paga:

```php
namespace App\Listeners;

use Noop\Commerce\Events\PayableSettled;
use Noop\Commerce\Models\Order;

class DeliverPurchase
{
    public function handle(PayableSettled $event): void
    {
        if (! $event->payable instanceof Order) {
            return;
        }

        foreach ($event->payable->items()->with('purchasable')->get() as $item) {
            $item->purchasable?->deliverTo($event->payable);   // o teu código; idempotente
        }
    }
}
```

O Laravel descobre o listener pelo tipo do argumento de `handle()`. O evento é independente do Moloni: dispara mesmo que a fatura-recibo ainda não exista.

## Plugin Filament

```php
use Noop\Commerce\Filament\CommercePlugin;

$panel->plugins([CommercePlugin::make()]);
```

Acrescenta o recurso **Encomendas** e o recurso **Pagamentos** (só de leitura: nada se cria, edita ou apaga diretamente). As traduções para `pt` vêm com o pacote. O acesso segue duas habilitações, verificadas com `$user->can()`:

```php
// config/commerce.php
'permissions' => [
    'view' => 'view orders',      // vê as encomendas e os pagamentos
    'manage' => 'manage orders',  // corre as ações (e também vê)
],
```

Define-as no teu `Gate` ou no teu pacote de permissões. Uma permissão `null` (ou vazia) nunca concede nada. O pacote regista as suas policies, exceto para um modelo em que já tenhas registado uma; um host que registe a sua própria policy para o modelo da encomenda ou para `Payment` tem de definir nela a habilitação `manage`, senão todas as ações ficam escondidas.

- **Encomendas.** Lista com número, nome, total, dívida, estado e circuito, e filtro por estado. A página da encomenda mostra os dados, as linhas, os pagamentos (aplicados, em revisão, anulados), as tentativas do EuPago e os documentos do Moloni, e um aviso "Documento em falta" quando falta um. Ações: **Registar pagamento** (inclui pagar outras encomendas em aberto do mesmo cliente, com um só recibo), **Gerar referência Multibanco**, **Pedir MB WAY**, **Emitir documentos**, **Pró-forma** e **Cancelar encomenda**.
- **Pagamentos.** Lista com filtro "em revisão" ligado por omissão. Ações: **Imputar a uma encomenda** (`allocate`), **Dar como tratado** (`resolveReview`) e **Anular** (`voidPayment`, só manuais sem recibo vivo).
- **Documentos.** As ações das linhas (PDF, converter pró-forma, reemitir, nota de crédito, cancelar) são as do `laravel-moloni` e seguem as **permissões do Moloni**, não estas.
- **Ações do EuPago.** As ações Multibanco e MB WAY e a tabela de tentativas só aparecem com o `laravel-eupago` instalado.
- Nenhuma ação abre uma transação de base de dados, mesmo num painel com `databaseTransactions()`; o que o pacote recusa aparece como notificação de erro.

## Testar na aplicação

Com `Moloni::fake()` e `Eupago::fake()` não há rede nem credenciais, e as regras e os eventos funcionam como em produção:

```php
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Facades\Commerce;
use Noop\Commerce\Models\Order;
use Noop\Eupago\Facades\Eupago;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Facades\Moloni;

it('settles the order and issues the invoice-receipt', function () {
    $moloni = Moloni::fake();
    $eupago = Eupago::fake();

    $order = Order::create(['billing_vat' => '509999999', 'is_business' => true]);
    $order->addItem(['name' => 'Consultoria', 'unit_net_price' => '10', 'tax_rate' => 23]);
    $order->confirm();

    $attempt = Commerce::multibanco($order);
    $eupago->pay($attempt);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
    $moloni->assertIssued(DocumentType::InvoiceReceipt, fn ($document) => $document->invoiceable->is($order));
});
```

`$eupago->expire($attempt)` simula a expiração, e `Moloni::fake()->failNext(...)` o falhanço de um documento. A tabela `settings` do spatie tem de existir na base de dados de testes (ver o README do `laravel-moloni`).

## Limites e notas de operação

- **Dinheiro que só aparece nas tabelas do EuPago.** Uma segunda transação numa tentativa já paga, um payable apagado e eventos `rejected` do webhook não passam pela fila de revisão deste pacote. Escuta `Noop\Eupago\Events\PaymentNeedsReview` e vigia o painel do EuPago.
- **Um `HandlesEupagoPayments` teu** substitui o do pacote (que só se regista se nada estiver ligado). Para o manteres, delega nele:

```php
use Noop\Commerce\Eupago\EupagoPayments;
use Noop\Eupago\Contracts\HandlesEupagoPayments;
use Noop\Eupago\Data\ConfirmedPayment;
use Noop\Eupago\Models\PaymentAttempt;

class HostPayments implements HandlesEupagoPayments
{
    public function __construct(private readonly EupagoPayments $commerce) {}

    public function canReceive(PaymentAttempt $attempt): bool
    {
        return $this->commerce->canReceive($attempt);
    }

    public function received(PaymentAttempt $attempt, ConfirmedPayment $payment): void
    {
        $this->commerce->received($attempt, $payment);
    }

    public function expired(PaymentAttempt $attempt): void
    {
        $this->commerce->expired($attempt);
    }
}
```

- **Não uses o "reenviar" da página de documentos do próprio Moloni** para recibos ou faturas-recibo de encomendas: reenvia os dados congelados, mesmo que o pagamento tenha sido anulado entretanto. Usa **Emitir documentos**.
- **Uma nota de crédito reduz a dívida mas não muda o estado da encomenda.** Cancela-a à mão se for o caso.
- **Uma nota de crédito emitida entre um pagamento e o seu recibo** pode fazer o Moloni recusar o recibo.
- **Ids.** `recorded_by_id`, `voided_by_id` e os ids dos morphs (`payable_*`, `buyer_*`, `purchasable_*`) são colunas inteiras: o utilizador e os modelos têm de ter chave primária inteira.
- **Só `EUR`.** O Moloni e o EuPago só trabalham com euros.
- **MySQL/MariaDB (InnoDB).** Os bloqueios de linha (`SELECT … FOR UPDATE`) dependem dele, como no `laravel-moloni`; PostgreSQL não é suportado. Dentro de uma transação tua, um deadlock chega-te já com a transação anulada: repete a unidade de trabalho.
- **Sem integração com pacotes de auditoria.** Quem registou ou anulou um pagamento fica só no id do utilizador.

## Testes do pacote

```bash
vendor/bin/pest
```

Usam SQLite em memória. O teste dos bloqueios de linha só corre com MySQL, numa base **dedicada e vazia** (apaga e recria todas as tabelas):

```bash
COMMERCE_TEST_MYSQL_DATABASE=laravel_commerce_test COMMERCE_TEST_MYSQL_HOST=127.0.0.1 \
COMMERCE_TEST_MYSQL_PORT=3306 COMMERCE_TEST_MYSQL_USERNAME=root COMMERCE_TEST_MYSQL_PASSWORD= \
vendor/bin/pest tests/Feature/LedgerMysqlTest.php
```

## Licença

MIT.
