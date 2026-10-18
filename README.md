# IVB Cartera

Cartera de saldo para clientes B2B de profesional.ivbwellness.com (WooCommerce). Primera campaña: **PREBLACK**.

## Qué hace
- Un pedido con **al menos 20 unidades** (sin material promocional ni regalos) hecho entre el **16 y el 31 de octubre de 2026** acumula el **5 %** de su importe en la cartera del cliente.
- Aviso en carrito y checkout ("este pedido acumula X"), tarjeta en la página de gracias, línea en el correo del pedido y sección **Mi cartera** en Mi cuenta (saldo, pendiente, movimientos).
- Administración en **WooCommerce > Cartera IVB**: resumen, clientes, detalle con ajuste manual, ajustes de la campaña, exportación CSV y caja "Cartera IVB" en cada pedido.

## Decisiones (a confirmar con IVB)
- **Base del 5 %** (desde 1.4.0): suma de las líneas del pedido que cuentan **con IVA**, sin recargo de equivalencia, sin envío y ya con descuentos aplicados. El saldo se gana y se gasta con IVA (todo en la tienda lleva IVA).
- **Unidades que cuentan**: no cuentan material promocional (marca `_mpbob_is_promotional`, categoría `material-promocional`, líneas `_mpbob_source` / `_ivb_promo`) ni líneas a 0 € (regalos, 10+2).
- **"Al menos 20"**: se usa >= 20, como dice el correo (la petición hablaba de "más de 20").
- **Cuándo se suma**: solo cuando el pedido está **procesando o completado**. Pendiente de pago o en espera no suma nada. Si un pedido ya sumado vuelve a en espera, se cancela o se reembolsa, el saldo se retira. Reembolsos parciales restan de la base.
- **Fecha que se mira**: la de creación del pedido, en zona horaria del sitio.
- **Códigos de descuento**: no afectan a la acumulación. Cualquier pedido con cupón acumula igualmente. Como la base son las líneas ya con descuentos, el 5 % se calcula sobre lo que realmente paga el cliente.
- **Canje (1.3.0)**: se gasta en el carrito. Con «Gastar saldo» activo, el carrito aplica solo un cupón virtual («Cartera IVB») por lo máximo posible: lo menor entre el saldo disponible y el **20 % por pedido** del importe de los productos (sin IVA, antes de cupones, sin material promocional ni líneas a 0 €). Es un descuento en % sobre las líneas elegibles, así que el IVA de cada tipo baja en proporción. El cliente puede **no usarlo en un pedido** (enlace en el aviso o «Remove» del cupón; vale para ese pedido, el siguiente vuelve a aplicarse solo). Al hacer el pedido el importe se descuenta del saldo (apunte `redeem`, en negativo, desde que se crea el pedido); si el pedido se cancela, falla o se reembolsa entero, se devuelve. El 5 % de un pedido se calcula sobre lo que paga el cliente: lo gastado de la cartera no genera saldo.
- **Plazo de uso**: hasta la fecha límite (por defecto 4 de diciembre); opcionalmente desde una fecha («Se puede gastar desde»).

## Visibilidad e interruptores (1.3.0)
- **Ganar saldo** y **Gastar saldo** se activan por separado (WooCommerce > Cartera IVB > Ajustes). Por defecto: ganar sí, gastar no (se activa cuando toque).
- «Quién la ve»: `admins` (por defecto: solo administradores, `manage_options`; **los clientes no ven ni acumulan nada**), `all` (todos los clientes) u `off`. Para abrirla a los clientes: «Todos los clientes» y apagar el modo de prueba. Actualizar el plugin no cambia lo que ya estuviera guardado.
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
- 1.3.1: la cartera sigue **inactiva para los clientes** (por defecto solo administradores y modo de prueba); se retira el paso que la abría a todos al actualizar.
- 1.3.2: el periodo de uso («desde / hasta») se muestra completo —«desde el X hasta el Y»— en Mi cartera, en los avisos del carrito, del checkout y del pedido y en el correo.
- 1.3.3: el aviso del carrito/checkout dice lo que sale de tu saldo (sin IVA) y, si el resumen muestra precios con IVA, también el importe con IVA que aparece allí, para que cuadren las dos cifras.
- 1.3.0: interruptores independientes para ganar y para gastar; **gastar saldo en el carrito** (descuento automático hasta el 20 % del importe de los productos, opción de no usarlo en un pedido, devolución si el pedido se cancela); bloque «Usar mi cartera» y movimientos de saldo gastado en Mi cartera; fechas de uso (desde / hasta); administración con saldo gastado. Probado en un WooCommerce real (WordPress Playground): carrito, checkout con transferencia, procesando y cancelado.
- 1.4.0: **el saldo se gana y se gasta CON IVA** (decisión de Adrià, 2026-10-18). Abono = % del importe de los productos con IVA tras descuentos; el gasto es un descuento en % cuyo importe CON IVA es el que sale del saldo (límite por pedido sobre la base con IVA). El recargo de equivalencia (segundo impuesto «R.E.» de las clases `*-re`) **nunca** entra: ni en la base ni en lo descontado. Los apuntes anteriores (sin IVA) no se tocan. Probado en WooCommerce real (Playground): pedido 2×100 € al 21 % → base 242 €, abono 12,10 €; cliente con R.E. → base 242 € (total 252,40 €); gasto de 30 € con IVA → cupón de 24,79 € sin IVA, total 212 € y apunte −30 €.
- 1.4.1: el descuento de la cartera ya no tiene enlace «Eliminar» y, si se quita por otra vía, **se vuelve a aplicar solo**; la única forma de no usarla en un pedido es «No usar mi cartera en este pedido» del aviso.
- 1.4.2: el enlace «Usar / No usar mi cartera en este pedido» ya no apunta a la URL AJAX de los fragmentos del carrito (salía una pantalla en blanco con código): vuelve siempre a la página del carrito o del checkout.
- 1.4.3: los enlaces «Usar / No usar mi cartera» que el navegador tenía guardados de versiones anteriores (apuntaban a `?wc-ajax=get_refreshed_fragments`) también funcionan: se procesan antes que WooCommerce AJAX y vuelven al carrito.
- 1.4.4: el importe que dice el aviso y el que se apunta en el saldo son exactamente el de la línea «Cartera IVB» del resumen (descuento + su impuesto; sin el recargo de equivalencia), sin diferencias de un céntimo por redondeo.
- 1.4.5: el límite de uso de «Mi cartera» aclara «(con IVA)».
