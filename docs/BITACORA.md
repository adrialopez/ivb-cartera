# Bitácora del plugin Cartera IVB (WooCommerce, profesional.ivbwellness.com)

Diario de lo hecho en la tienda WooCommerce (plugin, snippets y facturas). Lo de Shopify está en el repositorio ivb-b2b-shopify.

## 2026-10-18 (noche) · Adrià + Claude — Woo: sin céntimos sueltos, pedidos de prueba cancelados y numeración de facturas
- **Cartera IVB 1.4.6:** gastar todo el saldo lo deja en 0 (el pedido guarda lo previsto y el apunte usa eso si el cupón quedó a ≤ 2 céntimos por el redondeo). Snippet 163: la comprobación del cupón 2DESCTF al crear el pedido ignoraba mayúsculas y lo volvía a aplicar («ya se ha aplicado»): corregido con `has_discount`. Snippet 227: «Vaciar carrito» como enlace discreto.
- **Pedidos de prueba 166321 (#134972) y 166323 (#134973) cancelados** (la cartera de Adrià vuelve a 0). Sus facturas de prueba (2026-034624 y 2026-034625) se eliminaron y el contador de WooCommerce PDF Invoices (`ivb_wcpdf_invoice_number`) se dejó para que **la siguiente factura sea la 2026-034624**, sin saltos (copia de las filas en `~/backups/facturas/` del servidor). Revisión de la 034625 antes de borrarla: base 368,77 € (390,22 − 7,80 del 2 % − 13,65 de la cartera sin IVA), IVA 10 % 36,88 €, total 405,65 €: cuadraba con la base cobrada. Pendiente de decidir: la factura agrupa transferencia y cartera en «Descuento trf / cupón».

## 2026-10-18 (tarde) · Adrià + Claude — Woo: cartera con IVA, no eliminable, botella y snippet de transferencia
**Qué se hizo (todo en producción de profesional.ivbwellness.com, con copias en `~/backups/` del servidor)**
- **Cartera IVB 1.4.0/1.4.1:** el saldo se gana y se gasta **con IVA** (sin recargo de equivalencia); el apunte del pedido 166321 se recalculó: 13,66 € → **15,02 €** (base 300,47 €). El descuento de la cartera ya no tiene «Eliminar» y, si se quita, se vuelve a aplicar; solo «No usar mi cartera en este pedido» lo evita. Probado en WooCommerce real (Playground), no en el checkout de producción.
- **Snippet 163** (2DESCTF): `has_discount` antes de `apply_coupon` (adiós al aviso «ya se ha aplicado»).
- **Material Promocional B2B 0.23.3:** el botón «Ir al Carrito» del aviso de la ficha era ilegible; el aviso admite texto propio por producto/filtro. Snippet 233 (Promo 20 botellas, **sigue inactivo**): al activarlo, la ficha de la Botella IVB Negra explica que es un regalo que se añade solo en pedidos de más de 1.000 €.
**Queda:** activar el snippet 233 cuando se lance la promo; comprobar el checkout real de la cartera con IVA (aviso = línea del resumen).


- **Woo:** plugin Cartera IVB actualizado a 1.3.3 en producción (periodo completo en los avisos; el aviso del checkout muestra el importe sin IVA y con IVA). Copia previa en `~/backups/plugins/` del servidor.

## 2026-10-18 · En Woo la cartera se gana y se gasta CON IVA

**Decisión (Adrià):** en el plugin de Woo (Cartera IVB 1.4.0) el 5 % se calcula sobre el importe de los productos **con IVA** tras descuentos, y el saldo se gasta también con IVA («todo tiene IVA»); así el aviso, el resumen del carrito (que muestra precios con IVA) y el saldo cuadran. El recargo de equivalencia (segundo impuesto «R.E.») **nunca** entra en la base ni en lo descontado.
**Cómo cuadra con la regla fiscal:** el gasto es un cupón en % sobre las líneas elegibles, así que IVA y R.E. bajan en proporción (el impuesto sigue saliendo de la base cobrada). Un euro de saldo vale lo mismo al ganarlo que al gastarlo.
**Shopify no cambia:** allí la cartera sigue siendo sin IVA (la tienda muestra todo «sin IVA»); si se quiere igualar, hay que decidirlo aparte.

