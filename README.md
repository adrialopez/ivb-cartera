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
- **Canje (1.3.0)**: se gasta en el carrito. Con «Gastar saldo» activo, el carrito aplica solo un cupón virtual («Cartera IVB») por lo máximo posible: lo menor entre el saldo disponible y el **20 % por pedido** del importe de los productos (sin IVA, antes de cupones, sin material promocional ni líneas a 0 €). Es un descuento en % sobre las líneas elegibles, así que el IVA de cada tipo baja en proporción. El cliente puede **no usarlo en un pedido** (enlace en el aviso o «Remove» del cupón; vale para ese pedido, el siguiente vuelve a aplicarse solo). Al hacer el pedido el importe se descuenta del saldo (apunte `redeem`, en negativo, desde que se crea el pedido); si el pedido se cancela, falla o se reembolsa entero, se devuelve. El 5 % de un pedido se calcula sobre lo que paga el cliente: lo gastado de la cartera no genera saldo.
- **Plazo de uso**: hasta la fecha límite (por defecto 4 de diciembre); opcionalmente desde una fecha («Se puede gastar desde»).

## Visibilidad e interruptores (1.3.0)
- **Ganar saldo** y **Gastar saldo** se activan por separado (WooCommerce > Cartera IVB > Ajustes). Por defecto: ganar sí, gastar no (se activa cuando toque).
- «Quién la ve»: `all` (por defecto desde 1.3.0: todos los clientes), `admins` (solo administradores, `manage_options`) u `off`. Al actualizar a 1.3.0 se pone en `all` y se apaga el modo de prueba (una sola vez; después manda lo que se guarde).
- Con «Ganar saldo» apagado no se crean acumulaciones nuevas (las existentes se siguen anulando si el pedido se cancela o reembolsa). Con «Gastar saldo» apagado desaparecen el cupón, los avisos de uso y el bloque «Usar mi cartera». El modo de prueba permite a los administradores acumular fuera de fechas.
- En un pedido editado a mano en el administrador, el recálculo conserva el importe gastado de ese pedido; no recalcules pedidos con cartera si puedes evitarlo.

## Datos
Tabla `{prefix}ivbc_ledger` (una fila por apunte: acumulación `accrual`, ajuste `adjust`, saldo gastado `redeem`). El saldo es la suma de apuntes confirmados (los gastados restan).

## Versiones
- 1.0.0: acumulación, avisos, Mi cartera, administración.
- 1.1.0: el saldo solo se suma desde procesando; se elimina el concepto de saldo pendiente.
- 1.2.0: (retirada) bloqueo de pedidos con código de descuento.
- 1.2.1: cualquier cupón permite acumular; se retira el bloqueo de 1.2.0.
- 1.2.2: el aviso de carrito y checkout pasa a la zona de avisos de arriba (antes estaba entre las columnas del checkout y rompía la maquetación).
- 1.2.3: el aviso del carrito pasa a prioridad 12 (orden: acelerador, tu escala, cartera, seguir comprando) y comparte estilo con los demás avisos.
- 1.2.4: Mi cartera blindada frente al CSS de Betheme (márgenes y sangrías de listas): bloques alineados y con espacio entre ellos.
- 1.2.5: Mi cartera sin el nombre de la campaña ni el texto de canje pendiente.
- 1.3.0: cartera abierta a todos los clientes; interruptores independientes para ganar y para gastar; **gastar saldo en el carrito** (descuento automático hasta el 20 % del importe de los productos, opción de no usarlo en un pedido, devolución si el pedido se cancela); bloque «Usar mi cartera» y movimientos de saldo gastado en Mi cartera; fechas de uso (desde / hasta); administración con saldo gastado. Probado en un WooCommerce real (WordPress Playground): carrito, checkout con transferencia, procesando y cancelado.
