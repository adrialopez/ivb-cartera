# IVB Cartera

Cartera de saldo para clientes B2B de profesional.ivbwellness.com (WooCommerce). Primera campaña: **PREBLACK**.

## Qué hace
- Un pedido con **al menos 20 unidades** (sin material promocional ni regalos) hecho entre el **16 y el 31 de octubre de 2026** acumula el **5 %** de su importe en la cartera del cliente.
- Aviso en carrito y checkout ("este pedido acumula X"), tarjeta en la página de gracias, línea en el correo del pedido y sección **Mi cartera** en Mi cuenta (saldo, pendiente, movimientos).
- Administración en **WooCommerce > Cartera IVB**: resumen, clientes, detalle con ajuste manual, ajustes de la campaña, exportación CSV y caja "Cartera IVB" en cada pedido.

## Decisiones (a confirmar con IVB)
- **Base del 5 %**: suma de las líneas del pedido que cuentan, sin IVA, sin envío y ya con descuentos aplicados.
- **Unidades que cuentan**: no cuentan material promocional (marca `_mpbob_is_promotional`, categoría `material-promocional`, líneas `_mpbob_source` / `_ivb_promo`) ni líneas a 0 € (regalos, 10+2).
- **"Al menos 20"**: se usa >= 20, como dice el correo (la petición hablaba de "más de 20").
- **Cuándo se suma**: solo cuando el pedido está **procesando o completado**. Pendiente de pago o en espera no suma nada. Si un pedido ya sumado vuelve a en espera, se cancela o se reembolsa, el saldo se retira. Reembolsos parciales restan de la base.
- **Fecha que se mira**: la de creación del pedido, en zona horaria del sitio.
- **Códigos de descuento**: no afectan a la acumulación. Cualquier pedido con cupón acumula igualmente. Como la base son las líneas ya con descuentos, el 5 % se calcula sobre lo que realmente paga el cliente.
- **Canje**: **no implementado**. Solo se muestra el límite (20 % por pedido) y la fecha (4 de diciembre). 

## Visibilidad
Ajuste "Quién la ve": `admins` (por defecto: solo administradores, `manage_options`), `all` o `off`.
En `admins` los clientes no ven nada ni acumulan nada. El "modo de prueba" permite a los administradores acumular fuera de fechas.
Para abrirla a clientes: modo `all` y desactivar el modo de prueba (WooCommerce > Cartera IVB > Ajustes).

## Datos
Tabla `{prefix}ivbc_ledger` (una fila por apunte: acumulación, ajuste). El saldo es la suma de apuntes confirmados.

## Versiones
- 1.0.0: acumulación, avisos, Mi cartera, administración.
- 1.1.0: el saldo solo se suma desde procesando; se elimina el concepto de saldo pendiente.
- 1.2.0: (retirada) bloqueo de pedidos con código de descuento.
- 1.2.1: cualquier cupón permite acumular; se retira el bloqueo de 1.2.0.
- 1.2.2: el aviso de carrito y checkout pasa a la zona de avisos de arriba (antes estaba entre las columnas del checkout y rompía la maquetación).
- 1.2.3: el aviso del carrito pasa a prioridad 12 (orden: acelerador, tu escala, cartera, seguir comprando) y comparte estilo con los demás avisos.
- 1.2.4: Mi cartera blindada frente al CSS de Betheme (márgenes y sangrías de listas): bloques alineados y con espacio entre ellos.
- 1.2.5: Mi cartera sin el nombre de la campaña ni el texto de canje pendiente.
