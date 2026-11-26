# En memoria de Sergio Elio Alderete

Web conmemorativa con colecta para los gastos del servicio funerario:
pagos con Stripe, barra de progreso con el dinero real que llega a la cuenta,
correos de agradecimiento con Resend, mensajes de cariño, estadísticas con
exportación a PDF y un panel de administración.

Funciona en cualquier hosting con PHP 8.1+ y MySQL/MariaDB (cPanel, Hostinger, etc.).
No necesita instalar nada más ni usar la terminal.

---

## Puesta en marcha (10 minutos)

### 1. Crea la base de datos (cPanel → «Bases de datos MySQL»)
1. **Crear nueva base de datos**: por ejemplo `memorial` (cPanel le añade un prefijo: `usuario_memorial`).
2. **Agregar nuevo usuario**: elige un nombre y una contraseña larga. Apúntala.
3. **Agregar usuario a la base de datos**: marca **TODOS LOS PRIVILEGIOS**.

### 2. Sube la web
1. En cPanel → «Administrador de archivos», entra en la carpeta del dominio o subdominio
   (por ejemplo `public_html` o `public_html/sergio`).
2. Sube el ZIP `memorial-sergio.zip` y pulsa **Extraer**.
3. Activa el certificado SSL (https) del dominio (cPanel → «SSL/TLS Status» o «Let's Encrypt»).

### 3. Ejecuta el instalador
Abre `https://tu-dominio/install.php` (la web te lleva sola si aún no está instalada):
- comprueba que el servidor tiene todo lo necesario,
- pide los datos de la base de datos y la **contraseña del panel**,
- crea las tablas y la configuración, y **se borra solo** por seguridad.

> Hazlo justo después de subir los archivos: el instalador solo funciona una vez.

### 4. Conecta Stripe y Resend (panel → Conexiones)
Entra en `https://tu-dominio/admin/` y en la pestaña **Conexiones** pega:

**Stripe**
- *Clave secreta*: Stripe → Desarrolladores → Claves de API.
- *Secreto del webhook*: Stripe → Desarrolladores → Webhooks → **Añadir destino**:
  - URL: `https://tu-dominio/webhook.php` (el panel te la muestra)
  - Eventos: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `charge.refunded`

**Resend** (correos)
- *API key*: resend.com → API Keys → Create API Key.
- *Correo remitente*: de un dominio **verificado** en Resend (Domains → Add domain, y añade en tu DNS los registros que te indica).
- *Avisarme de cada donación en*: tu correo, si quieres recibir un aviso por cada aporte.

Usa los botones **Probar conexión con Stripe** y **Enviar correo de prueba**.

### 5. Prueba y publica
1. Con la clave `sk_test_...` haz una donación con la tarjeta `4242 4242 4242 4242` (fecha futura, cualquier CVC).
   Comprueba que sube la barra y que llega el correo de agradecimiento.
2. Cambia a la clave `sk_live_...` (y crea el webhook también en modo real).
   Las donaciones de prueba y las reales se cuentan por separado: la barra empieza limpia.
3. En **Ajustes**, revisa los textos, las fechas y la foto.

---

## El dinero, separado en Stripe

- **Recomendado: una cuenta de Stripe solo para la colecta.** En Stripe, selector de cuenta → **Nueva cuenta**
  («En memoria de Sergio Elio Alderete») con su propia cuenta bancaria de destino. Así el dinero nunca se mezcla.
- Además, cada pago lleva la etiqueta `campaign = memoria-sergio-elio-alderete` y la descripción
  «Donación en memoria de Sergio Elio Alderete». **La web solo cuenta los pagos con esa etiqueta**:
  cualquier otro cobro de la cuenta no aparece nunca en la barra ni en las estadísticas.

## Dinero real y comisiones

Por cada pago la web guarda, desde Stripe: importe donado, **comisión de Stripe**, reembolsos y
**neto que llega a la cuenta**. La barra pública muestra el neto (se puede cambiar en Ajustes).
La pestaña **Estadísticas** muestra el desglose completo y el saldo real de la cuenta de Stripe.

## El panel de administración

| Pestaña | Para qué sirve |
|---|---|
| **Estadísticas** | Neto, bruto, comisiones, reembolsos, media/mediana, evolución por día, por país, por método de pago, por hora, y el detalle de cada transacción (fecha, hora, país, tarjeta, comisión, neto). Exporta **PDF** (anónimo o con nombres, nunca con correos) y **CSV** para Excel. |
| **Actualizaciones** | Publica, edita y borra novedades del proceso. |
| **Donaciones** | Lista de aportes, estado del correo de agradecimiento y botón para reenviarlo. |
| **Mensajes** | Mensajes que dejan los donantes al pagar. Tú decides cuáles se publican en la web. |
| **Correos** | Texto del agradecimiento automático, envío de un mensaje a todos los donantes y registro de cada correo enviado. |
| **Ajustes** | Foto, nombre, fechas, textos, meta, qué muestra la barra y cerrar la colecta. |
| **Conexiones** | Dirección de la web, claves de Stripe y Resend, pruebas y cambio de contraseña. |

## Seguridad

- Contraseña del panel cifrada (bcrypt); bloqueo tras 5 intentos fallidos por IP (y 60 en total) durante 15 minutos.
- Sesión del panel con cookie segura (`HttpOnly`, `Secure`, `SameSite=Strict`), caducidad por inactividad (2 h) y máxima (12 h).
- Protección CSRF en todos los formularios del panel.
- Cabeceras de seguridad: Content-Security-Policy estricta (sin JavaScript en línea), anti-clickjacking, `nosniff`, HSTS en https.
- Todo lo que se muestra se escapa (anti-XSS). Consultas a MySQL con sentencias preparadas (anti inyección SQL).
- Webhook de Stripe con verificación de firma y ventana de 5 minutos (anti-falsificación y anti-repetición).
- Los pagos se confirman siempre consultando a Stripe, nunca con datos que envíe el navegador.
- Anti-abuso en el formulario de donación (campo trampa para bots, límite de intentos por IP, importe mínimo de 5 €)
  para frenar las «pruebas de tarjetas robadas».
- Fotos: se comprueban y se vuelven a generar con GD (se eliminan metadatos y cualquier código oculto); la carpeta `uploads` no ejecuta scripts.
- `config.php`, `lib/` y `data/` bloqueados desde el navegador; sin listado de carpetas; los errores nunca se muestran al visitante.
- CSV protegido contra inyección de fórmulas en Excel.
- Opcional: limita el panel a tus IPs con `admin_allowed_ips` en `config.php`.

Recomendaciones: usa https (activa la redirección en `.htaccess`), una contraseña larga y única,
y activa la verificación en dos pasos en tu cuenta de Stripe y de Resend.

## Copia de seguridad

Todo está en la base de datos (tabla `memorial_store`) más la foto en `uploads/`.
Exporta la base de datos desde cPanel → phpMyAdmin → Exportar de vez en cuando.

## Estructura

```
index.php        Portada: homenaje, colecta, mensajes y actualizaciones
donar.php        Crea el pago en Stripe Checkout
gracias.php      Agradecimiento (confirma el pago con Stripe)
webhook.php      Avisos de Stripe (pagos y reembolsos)
estado.php       Datos de la barra (se actualiza sola cada minuto)
install.php      Instalador (se borra solo al terminar)
admin/           Panel de administración
lib/             Lógica: datos, Stripe, correo, estadísticas, PDF
assets/          Estilos, scripts y foto de Sergio
uploads/         Foto subida desde el panel
```
