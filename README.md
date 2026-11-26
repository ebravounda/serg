# En memoria de Sergio Elio Alderete — web de colecta

Página conmemorativa con una colecta mediante Stripe, una barra de progreso hacia la meta (2.000 €)
y un panel de administración para publicar actualizaciones del proceso.

Está hecha en PHP sin dependencias. Funciona en cualquier hosting con PHP 8.1 o superior y la extensión cURL
(cPanel, por ejemplo). Súbela a su propio dominio o subdominio, por ejemplo `sergio.tudominio.com`.

## Estructura

```
index.php          Portada: homenaje, barra de progreso, botón "Realiza tu donación" y actualizaciones
donar.php          Crea el pago en Stripe Checkout y redirige al donante
gracias.php        Página de agradecimiento (confirma el pago con Stripe)
webhook.php        Recibe los avisos de Stripe (pagos y reembolsos)
estado.php         Datos de la barra en JSON (la portada se actualiza cada minuto)
admin/             Panel: actualizaciones, donaciones y ajustes (textos, foto, meta)
data/              Datos guardados (protegidos, no se pueden descargar)
uploads/           Foto de Sergio subida desde el panel
config.example.php Plantilla de configuración → cópiala como config.php
```

## Puesta en marcha

1. **Sube el contenido de este repositorio** a la raíz del dominio o subdominio. Activa el SSL (https).
2. **Crea `config.php`** copiando `config.example.php`.
3. **Contraseña del panel.** Genera el hash (en tu ordenador o en la Terminal de cPanel):
   ```bash
   php -r 'echo password_hash("UNA-CONTRASEÑA-LARGA", PASSWORD_DEFAULT), PHP_EOL;'
   ```
   Pega el resultado en `admin_password_hash`. El panel está en `https://tu-dominio/admin/`.
4. **Stripe** (ver la sección siguiente): pega la clave secreta en `stripe_secret_key` y el secreto del webhook en `stripe_webhook_secret`.
5. Entra en el panel → **Ajustes**: sube la foto de Sergio, añade las fechas y revisa los textos.
6. Haz una donación de prueba con claves `sk_test_...` y la tarjeta `4242 4242 4242 4242`
   (cualquier fecha futura y CVC). Cuando todo esté bien, cambia a las claves `sk_live_...`
   y crea el webhook también en modo real.

Los permisos de escritura: `data/` y `uploads/` deben poder ser escritas por PHP (normalmente 755 en cPanel basta).

## Cómo se separa el dinero en Stripe

Hay dos niveles. Recomendamos usar los dos.

### 1. Una cuenta de Stripe solo para la colecta (separación real del dinero)
En el Dashboard de Stripe, en el selector de cuenta (arriba a la izquierda) → **Nueva cuenta**,
llamada por ejemplo «En memoria de Sergio Elio Alderete». Esa cuenta tiene su propio saldo,
sus propias claves de API y su propia **cuenta bancaria de destino** para los pagos (payouts).
Así el dinero de la colecta nunca se mezcla con nada más y se transfiere a la cuenta bancaria que elijáis
(por ejemplo, la de la persona que paga a la funeraria). Usa las claves de **esa** cuenta en `config.php`.

Stripe tendrá que verificar la cuenta (datos de la persona responsable y su cuenta bancaria) antes de pagar los fondos.
En «descripción del negocio» indicad con claridad que es una colecta entre amigos para gastos funerarios.

### 2. Etiqueta en cada pago (la barra solo cuenta los de este botón)
Cada pago creado desde el botón lleva:
- `metadata.campaign = memoria-sergio-elio-alderete` (en la sesión de Checkout y en el PaymentIntent)
- descripción «Donación en memoria de Sergio Elio Alderete»
- el texto `SERGIO ALDERETE` en el extracto bancario del donante

La web **solo** suma pagos con esa etiqueta. Cualquier otro cobro de la cuenta (aunque compartáis cuenta)
no aparece en la barra. En el Dashboard podéis localizar estos pagos buscando «Donación en memoria»
(la descripción) y ver la etiqueta en el detalle de cada pago. Opcional: crea un producto en Stripe y pon su `prod_...` en `stripe_product_id`
para ver todas las donaciones agrupadas bajo ese producto.

## Webhook (recomendado)

Hace que la barra suba al instante y que los reembolsos se descuenten. En Stripe:
**Desarrolladores → Webhooks → Añadir destino**
- URL: `https://tu-dominio/webhook.php`
- Eventos: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `charge.refunded`

Copia el «secreto de firma» (`whsec_...`) en `stripe_webhook_secret`.

Aunque el webhook falle, la barra no se pierde pagos: la página de agradecimiento confirma cada pago, y la web
se sincroniza sola con Stripe cada 10 minutos. En el panel → **Donaciones** también hay un botón «Sincronizar con Stripe».

## Panel de administración

- **Actualizaciones**: publicar, editar y borrar novedades del proceso (con fecha). Aparecen en la portada, la más reciente primero.
- **Donaciones**: listado con fecha, nombre, email (solo visible en el panel), importe y enlace al pago en Stripe. Total.
- **Ajustes**: foto, nombre, fechas, frase, texto de homenaje, nota sobre el destino del dinero, pie de página,
  meta en euros y un interruptor para cerrar la colecta.

## Copia de seguridad

Todo lo editable está en `data/` (y la foto, en `uploads/`). Descarga esas dos carpetas de vez en cuando.
