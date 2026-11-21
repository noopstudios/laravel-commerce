# Registo de alterações

Todas as alterações relevantes deste pacote ficam aqui. O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-PT/1.1.0/).

## 0.1.2 — 2026-11-21

### Alterado

- O pacote passa a exigir `noopstudios/laravel-moloni` `^0.1.2` e `noopstudios/laravel-eupago` `^0.1` do Packagist, em vez de caminhos locais.

## 0.1.1 — 2026-11-21

### Alterado

- O `CommercePlugin` passa a exigir o `MoloniPlugin` no mesmo painel (a página da encomenda usa as ações de documentos do Moloni, e o PDF é uma rota que o `MoloniPlugin` regista).

### Corrigido

- As ações da tabela de documentos da encomenda atuavam sobre o primeiro documento da encomenda em vez do escolhido.

## 0.1.0 — 2026-11-18

### Adicionado

- Encomendas (`Order`) com linhas (`OrderItem`), totais calculados como o Moloni os calcula, estados `draft`, `pending_payment`, `partially_paid`, `paid`, `expired` e `cancelled` (escritos só pelo pacote), `confirm()` e `cancel()`, e número `PREFIXO-<id>`.
- Três níveis de adaptação: usar a encomenda do pacote (`meta`), estendê-la (`commerce.models.order`, alias morph `order`) ou substituí-la por um modelo próprio (`Commerce::ignoreOrderMigrations()`, contrato `Payable` e `Invoiceable` do `laravel-moloni`).
- Pagamentos e imputações (`Payment`, `PaymentAllocation`): `Commerce::recordPayment()` com chave de idempotência, um recibo para várias encomendas, `Commerce::dueCents()`, `Commerce::voidPayment()` (só pagamentos manuais).
- Fila de revisão para pagamentos que não se podem imputar (tardio, duplicado, em excesso, valor diferente no circuito A, moeda errada, transação repetida): `Commerce::allocate()`, `Commerce::resolveReview()` e o evento `PaymentNeedsReview`.
- Dois circuitos de faturação: pagar primeiro (fatura-recibo) e faturar primeiro (fatura no `confirm()` e um recibo por pagamento). Pagar nunca depende de faturar: se o Moloni falhar, a encomenda fica paga e o documento pode ser pedido de novo.
- Ligação opcional ao `laravel-eupago`: `Commerce::multibanco()` e `Commerce::mbway()`, `EupagoPayments` como `HandlesEupagoPayments`, e expiração do circuito A pela última referência Multibanco em aberto.
- `commerce:recover`, agendado de cinco em cinco minutos, para os documentos que um processo interrompido deixou por pedir.
- Eventos `PayableSettled`, `PayableExpired` e `PaymentNeedsReview`, despachados depois do commit.
- Plugin Filament v5 opcional (`CommercePlugin`): encomendas e pagamentos, com as ações de pagamento, referências, documentos, revisão e anulação, guardado pelas permissões `commerce.permissions.view` e `commerce.permissions.manage`; traduções em `pt`.
- README em português europeu.

### Notas

- Só `EUR`. MySQL/MariaDB (InnoDB) para os bloqueios de linha.
- Os ids de utilizador (`recorded_by_id`, `voided_by_id`) e dos morphs são colunas inteiras.
