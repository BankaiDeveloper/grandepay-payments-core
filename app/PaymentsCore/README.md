# PaymentsCore

Modulo logico do dominio de pagamentos a ser extraido do monolito Laravel.

Subpastas esperadas:

- `Application`: casos de uso
- `Domain`: entidades, regras, value objects e contratos
- `Infrastructure`: Eloquent, Redis, queue, HTTP client e providers

O objetivo e manter o centro do dominio desacoplado de HTTP e do framework onde for viavel.
