# Guía paso a paso para publicar la web de Sergio

Esta guía te lleva desde cero hasta recibir la primera donación real.
Calcula **1 hora** la primera vez (la mayor parte es esperar verificaciones de Stripe y Resend).

> Los nombres de los menús pueden variar un poco según tu hosting o si Stripe/Resend
> cambian su diseño, pero los pasos son siempre los mismos.

---

## Índice

0. [Antes de empezar: qué necesitas](#0-antes-de-empezar-qué-necesitas)
1. [Preparar Stripe (pagos)](#1-preparar-stripe-pagos)
2. [Preparar Resend (correos)](#2-preparar-resend-correos)
3. [Preparar el hosting (cPanel)](#3-preparar-el-hosting-cpanel)
4. [Crear la base de datos MySQL](#4-crear-la-base-de-datos-mysql)
5. [Subir los archivos](#5-subir-los-archivos)
6. [Ejecutar el instalador](#6-ejecutar-el-instalador)
7. [Conectar Stripe y Resend en el panel](#7-conectar-stripe-y-resend-en-el-panel)
8. [Personalizar la web](#8-personalizar-la-web)
9. [Hacer una donación de prueba](#9-hacer-una-donación-de-prueba)
10. [Pasar a modo real (dinero de verdad)](#10-pasar-a-modo-real-dinero-de-verdad)
11. [Compartir la web](#11-compartir-la-web)
12. [Uso del día a día](#12-uso-del-día-a-día)
13. [Si algo falla](#13-si-algo-falla)
14. [Lista de comprobación final](#14-lista-de-comprobación-final)

---

## 0. Antes de empezar: qué necesitas

| Qué | Para qué | Dónde |
|---|---|---|
| El archivo **`memorial-sergio.zip`** | Es la web completa | Te lo he enviado |
| Un **hosting con cPanel** (o similar) con PHP 8.1+ y MySQL | Donde vive la web | El que ya usáis (por ejemplo el de toralpyc.cl) |
| Un **dominio o subdominio** | La dirección de la web | Ej. `sergio.toralpyc.cl` o un dominio nuevo |
| Una cuenta de **Stripe** | Cobrar las donaciones | https://dashboard.stripe.com |
| Una cuenta de **Resend** | Enviar los correos de agradecimiento | https://resend.com |
| Los **datos bancarios** de quien recibirá el dinero | Stripe transfiere ahí lo recaudado | — |

Ten a mano un bloc de notas para apuntar: **usuario y contraseña de MySQL**, **contraseña del panel**, y las claves de Stripe y Resend.

---

## 1. Preparar Stripe (pagos)

### 1.1 Crear una cuenta solo para la colecta (recomendado)

Así el dinero de Sergio nunca se mezcla con otro.

1. Entra en https://dashboard.stripe.com (o crea una cuenta nueva si no tienes).
2. Arriba a la izquierda, pulsa el **nombre de la cuenta** → **Nueva cuenta**.
3. Nombre: `En memoria de Sergio Elio Alderete`. País: el de la persona responsable (ej. España).
4. Ya dentro de la nueva cuenta, comprueba arriba a la izquierda que estás en ella.

### 1.2 Activar la cuenta (necesario para recibir dinero real)

1. Stripe te mostrará **«Activar pagos»** o **«Completar perfil»**. Pulsa ahí.
2. Tipo de negocio: **Particular / Persona física** (o asociación si la hubiera).
3. Descripción de la actividad, por ejemplo:
   > Colecta entre amigos para cubrir los gastos del servicio funerario de Sergio Elio Alderete. Las donaciones se reciben a través de la web https://sergio.tudominio.com
4. Datos personales del responsable y **cuenta bancaria (IBAN)** donde Stripe transferirá el dinero.
5. Stripe puede tardar desde minutos hasta 1–2 días en verificar. **Mientras tanto puedes seguir con toda la guía en modo de prueba.**

### 1.3 Copiar las claves de PRUEBA

1. Activa el **modo de prueba** (interruptor «Modo de prueba» / «Entorno de prueba» arriba a la derecha).
2. Ve a **Desarrolladores → Claves de API**.
3. En **Clave secreta**, pulsa **Mostrar** y copia el valor que empieza por **`sk_test_`**. Apúntalo.

> La **clave publicable** (`pk_...`) **no** hace falta.

> ⚠️ La clave secreta es como la llave de la caja: no la mandes por WhatsApp ni la publiques.

El webhook lo crearemos en el paso 7, cuando la web ya esté subida.

---

## 2. Preparar Resend (correos)

Resend es quien envía los correos de agradecimiento. Para que lleguen a la bandeja de entrada (y no a spam)
hay que **verificar un dominio**.

### 2.1 Crear la cuenta
1. Entra en https://resend.com y regístrate (el plan gratuito suele bastar: unos 3.000 correos al mes y 100 al día).

### 2.2 Verificar tu dominio
1. En Resend, menú **Domains → Add Domain**.
2. Escribe el dominio desde el que se enviará, por ejemplo `toralpyc.cl` (o un subdominio como `mail.toralpyc.cl`).
3. Resend te mostrará **3–4 registros DNS** (tipo `TXT` y `MX`), cada uno con *Nombre* y *Valor*.
4. En cPanel → **Zone Editor** (Editor de zona DNS) → tu dominio → **+ Agregar registro**:
   añade **cada registro exactamente** como lo muestra Resend (tipo, nombre y valor; copia y pega).
   - Si el DNS de tu dominio está en otro sitio (Cloudflare, GoDaddy, Nic Chile…), añádelos allí.
5. Vuelve a Resend y pulsa **Verify DNS Records**. Puede tardar de minutos a unas horas. Cuando ponga **Verified** ✅, listo.

### 2.3 Crear la API key
1. En Resend → **API Keys → Create API Key**.
2. Nombre: `Memorial Sergio`. Permiso: **Sending access**. Dominio: el que verificaste.
3. Copia la clave que empieza por **`re_`** (solo se muestra una vez). Apúntala.

### 2.4 Decide el remitente
Por ejemplo: `memoria@toralpyc.cl` con el nombre `Amigos de Sergio`.
El correo debe ser **del dominio verificado** (la parte de después de la @).

---

## 3. Preparar el hosting (cPanel)

### 3.1 Crear el subdominio (si no usas un dominio propio)
1. cPanel → **Dominios** (o **Subdominios**) → **Crear un nuevo dominio**.
2. Dominio: `sergio.toralpyc.cl` (o el que prefieras).
3. **Raíz del documento** (carpeta): deja la que propone, por ejemplo `public_html/sergio` o `sergio.toralpyc.cl`.
   **Apunta esa carpeta**: ahí subiremos la web.
4. Pulsa **Enviar / Crear**.

### 3.2 Activar el certificado de seguridad (https)
1. cPanel → **SSL/TLS Status** (Estado de SSL/TLS).
2. Marca tu nuevo subdominio y pulsa **Run AutoSSL**. En unos minutos aparecerá el candado verde.
   (En algunos hostings se llama **Let's Encrypt** o **SSL gratuito**.)

### 3.3 Versión de PHP y extensiones
1. cPanel → **Seleccionar versión de PHP** (o **MultiPHP Manager**).
2. Elige **PHP 8.1, 8.2 o 8.3** para tu dominio.
3. Si aparece la pestaña **Extensiones**, comprueba que están marcadas:
   `curl`, `pdo_mysql`, `mbstring`, `gd`, `fileinfo` (y si existe, `intl`).

> El instalador comprobará todo esto y te dirá si falta algo.

---

## 4. Crear la base de datos MySQL

1. cPanel → **Asistente de bases de datos MySQL** (MySQL Database Wizard).
2. **Paso 1 – Crear base de datos**: escribe `memorial` → *Siguiente*.
   cPanel le pone un prefijo: el nombre real será algo como **`tuusuario_memorial`**. **Apúntalo completo.**
3. **Paso 2 – Crear usuario**: escribe `memorial` y pulsa **Generador de contraseñas** (o pon una larga).
   El usuario real será **`tuusuario_memorial`**. **Apunta usuario y contraseña.** → *Crear usuario*.
4. **Paso 3 – Privilegios**: marca **TODOS LOS PRIVILEGIOS** → *Hacer cambios*.

Listo. No hace falta crear tablas: lo hace el instalador.

---

## 5. Subir los archivos

1. cPanel → **Administrador de archivos**.
2. Entra en la carpeta del subdominio que apuntaste en el paso 3.1 (ej. `public_html/sergio`).
   - Si la carpeta tiene un archivo de ejemplo (`index.html`, `default.php`, `cgi-bin`), puedes borrar el `index.html`.
3. Pulsa **Cargar** (Upload) y sube **`memorial-sergio.zip`**. Espera a que la barra llegue al 100 %.
4. Vuelve a la carpeta, haz clic derecho sobre el ZIP → **Extraer** → confirma que se extrae **en esa misma carpeta**.
5. Comprueba que ahora ves directamente estos archivos (y **no** una carpeta dentro de otra):
   ```
   admin/   assets/   data/   lib/   uploads/
   index.php   install.php   donar.php   gracias.php   webhook.php   estado.php   .htaccess ...
   ```
   > Si al extraer se creó una subcarpeta, entra en ella, selecciona todo, **Mover** a la carpeta superior.
   > Para ver `.htaccess` activa **Configuración → Mostrar archivos ocultos**.
6. Borra el archivo `memorial-sergio.zip` (ya no hace falta).

**Permisos:** normalmente ya están bien. Si el instalador se queja, haz clic derecho en las carpetas `data` y `uploads` → **Cambiar permisos** → `755`.

---

## 6. Ejecutar el instalador

> Hazlo **justo después de subir los archivos**: el instalador funciona una sola vez.

1. Abre en el navegador: **`https://sergio.tudominio.com/install.php`**
   (o simplemente `https://sergio.tudominio.com`: si no está instalada, te lleva sola al instalador).
2. **Comprobación del servidor**: todo debe salir con ✓.
   - ✕ en algo → sigue la indicación que aparece debajo (normalmente activar una extensión en el paso 3.3) y recarga.
   - «!» en *https* → el certificado aún no está activo (paso 3.2). Puedes seguir, pero actívalo antes de publicar.
3. **Base de datos MySQL**:
   - *Nombre de la base de datos*: `tuusuario_memorial` (completo, con prefijo).
   - *Usuario*: `tuusuario_memorial`.
   - *Contraseña*: la del paso 4.
   - *Opciones avanzadas*: no las toques (servidor `localhost`, puerto `3306`).
4. **Web y acceso al panel**:
   - *Dirección de la web*: comprueba que sea `https://sergio.tudominio.com` (con **https**).
   - *Contraseña del panel*: mínimo 10 caracteres. **Esta es la que usarás para entrar al panel. Apúntala.**
5. Pulsa **Instalar**. Verás **«¡Listo! La web está instalada»**.
   El instalador se borra solo. Si te avisa de que no pudo borrarse, bórralo tú desde el Administrador de archivos.

---

## 7. Conectar Stripe y Resend en el panel

1. Entra en **`https://sergio.tudominio.com/admin/`** con la contraseña del panel.
2. Ve a la pestaña **Conexiones**.

### 7.1 Dirección de la web
Debe aparecer `https://sergio.tudominio.com`. Si no, escríbela.

### 7.2 Stripe
1. **Clave secreta**: pega la `sk_test_...` del paso 1.3.
2. **Secreto del webhook** — créalo ahora en Stripe (en **modo de prueba**):
   1. Stripe → **Desarrolladores → Webhooks** → **Añadir destino** (o *Add endpoint* / *Crear destino de evento*).
   2. **URL del endpoint**: `https://sergio.tudominio.com/webhook.php`
      (el panel te muestra la dirección exacta debajo del campo).
   3. **Eventos que escuchar** — busca y marca estos tres:
      - `checkout.session.completed`
      - `checkout.session.async_payment_succeeded`
      - `charge.refunded`
   4. Guarda. En la página del webhook, en **Secreto de firma** (*Signing secret*), pulsa **Mostrar** y copia el valor `whsec_...`.
   5. Pégalo en el panel, en **Secreto del webhook**.
3. **ID de producto**: déjalo vacío.

### 7.3 Resend
- **API key**: la `re_...` del paso 2.3.
- **Correo remitente**: ej. `memoria@toralpyc.cl` (del dominio verificado).
- **Nombre del remitente**: ej. `Amigos de Sergio`.
- **Responder a**: tu correo personal (si un donante responde al agradecimiento, te llega a ti).
- **Avisarme de cada donación en**: tu correo, si quieres un aviso por cada aporte.

### 7.4 Guardar y comprobar
1. Pulsa **Guardar conexiones**. Las claves se muestran ocultas (`sk_test_…1234`): es normal.
2. Abajo, en **Comprobar**:
   - **Probar conexión con Stripe** → debe decir *«Conexión con Stripe correcta (modo de prueba)»*.
   - **Enviar correo de prueba** a tu correo → revisa tu bandeja (y spam). Debe llegar un correo con la foto de Sergio.

---

## 8. Personalizar la web

Panel → **Ajustes**:

1. **Foto**: ya viene la de Sergio. Puedes subir otra (mejor cuadrada, con la cara centrada).
2. **Fechas**: ej. `1985 – 2026`.
3. **Frase bajo el nombre**, **texto de homenaje** y **nota sobre el destino del dinero**: revisadlos entre todos y ajustadlos a vuestras palabras.
4. **Meta (€)**: `2000`.
5. **La barra muestra**: deja *«El dinero real que llega a la cuenta»* (descuenta las comisiones de Stripe).
6. **Guardar ajustes**.

Panel → **Correos**: revisa el texto del **agradecimiento automático** y pulsa **Enviarme un ejemplo** para verlo en tu correo.

Panel → **Actualizaciones**: publica la primera, por ejemplo *«Abrimos la colecta»*.

---

## 9. Hacer una donación de prueba

Con las claves de prueba **no se cobra dinero real**.

1. Abre la web `https://sergio.tudominio.com` (mejor desde el móvil, para verla como la verá la gente).
2. Elige **20 €**, escribe un **nombre y apellido** (ej. `Juan Pérez`) → **Realiza tu donación**.
3. En la página de pago de Stripe usa:
   - Correo: **el tuyo** (para recibir el agradecimiento)
   - Tarjeta: **`4242 4242 4242 4242`**
   - Fecha: cualquiera futura (ej. `12/30`) · CVC: `123` · Nombre y país: los que quieras
   - *Mensaje para la familia*: escribe algo de prueba.
4. Pulsa **Donar**. Debes ver la página **«Gracias»**.
5. Comprueba:
   - ✅ La **barra** de la web ha subido (unos 19,45 €: 20 € menos la comisión de Stripe).
   - ✅ Debajo de la barra aparece **«Última donación: Juan Pérez»**.
   - ✅ Te ha llegado el **correo de agradecimiento** (y el **aviso** si lo configuraste).
   - ✅ Panel → **Estadísticas**: aparece la donación con hora, país, tarjeta, comisión y neto.
   - ✅ Panel → **Mensajes**: aparece tu mensaje (puedes publicarlo u ocultarlo).
   - ✅ Panel → Estadísticas → **Descargar PDF**: se descarga el informe.
   - ✅ Stripe → Desarrolladores → Webhooks → tu webhook: los envíos aparecen en verde (200).

Si todo está bien, ¡la web funciona! 🎉

---

## 10. Pasar a modo real (dinero de verdad)

Solo cuando Stripe haya **activado tu cuenta** (paso 1.2).

1. En Stripe, **desactiva el modo de prueba**.
2. **Desarrolladores → Claves de API** → copia la clave secreta **`sk_live_...`**.
3. **Desarrolladores → Webhooks** → crea **otro** webhook igual que en el paso 7.2 (misma URL y mismos 3 eventos), ahora en modo real,
   y copia su nuevo **`whsec_...`**.
4. Panel → **Conexiones**: pega la `sk_live_...` en *Clave secreta* y el nuevo `whsec_...` en *Secreto del webhook* → **Guardar conexiones**.
5. Pulsa **Probar conexión con Stripe** → debe decir *«modo real»*. El aviso amarillo de «modo de prueba» desaparece.
6. La barra vuelve a 0 €: las donaciones de prueba no cuentan en modo real.
7. (Recomendado) Haz una donación real pequeña (5 €) para comprobar todo. Si quieres, luego la reembolsas desde Stripe:
   la web la descontará sola.

### Activar la redirección a https
En el Administrador de archivos, edita `.htaccess` y **quita el `#`** del principio de estas 3 líneas:
```
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```
Guarda y comprueba que `http://sergio.tudominio.com` te lleva a `https://`.

---

## 11. Compartir la web

- Comparte el enlace `https://sergio.tudominio.com` por WhatsApp, Instagram, Facebook o correo.
  Al pegarlo aparece la foto y el título.
- La web está marcada para **no aparecer en Google**: solo la ve quien tiene el enlace.
- Texto sugerido para WhatsApp:
  > Hemos abierto una colecta para despedir a Sergio como se merece y ayudar con los gastos del funeral. Cualquier aporte ayuda ❤️ https://sergio.tudominio.com

---

## 12. Uso del día a día

| Quiero… | Dónde |
|---|---|
| Ver cuánto llevamos de verdad | Panel → **Estadísticas** (neto, comisiones, saldo de Stripe) |
| Contar una novedad en la web | Panel → **Actualizaciones** → *Publicar* |
| Publicar un mensaje de cariño | Panel → **Mensajes** → *Publicar en la web* |
| Enseñar las cuentas a alguien | Panel → Estadísticas → **Descargar PDF (anónimo)** |
| Tener todo en Excel | Panel → Estadísticas → **Excel / CSV completo** (incluye correos: solo para vosotros) |
| Reenviar un agradecimiento | Panel → **Donaciones** → *Reenviar* |
| Quitar de la web un nombre inapropiado | Panel → **Donaciones** → *Ocultar nombre en la web* (aparecerá como «Anónimo») |
| Escribir a todos los donantes | Panel → **Correos** → *Enviar un mensaje a los donantes* (prueba primero con *Enviarme una prueba*) |
| Cerrar la colecta | Panel → **Ajustes** → desmarca *Donaciones abiertas* |
| Cambiar la contraseña | Panel → **Conexiones** → *Contraseña del panel* |
| Ver cuándo llega el dinero al banco | Stripe → **Saldos** / **Pagos (transferencias)** |

**Copia de seguridad** (una vez por semana): cPanel → **phpMyAdmin** → elige la base de datos → **Exportar** → *Continuar*.

---

## 13. Si algo falla

| Problema | Solución |
|---|---|
| El instalador marca ✕ en *PHP*, *cURL*, *PDO MySQL* o *mbstring* | cPanel → **Seleccionar versión de PHP**: elige 8.1+ y activa la extensión. Recarga el instalador. |
| *«MySQL rechazó el usuario o la contraseña»* | Revisa usuario y contraseña (con prefijo). En **Bases de datos MySQL** comprueba que el usuario está **añadido a la base de datos** con todos los privilegios. |
| *«La base de datos no existe»* | Escribe el nombre **completo con prefijo** (`tuusuario_memorial`). |
| Al abrir la web sale **error 500** | Probablemente la versión de PHP es antigua (necesita 8.1+). Si persiste: cPanel → **Errores** o el archivo `error_log` de la carpeta te dirá la causa. |
| `install.php` da **«No encontrado»** | La web ya está instalada (es normal). Entra en `/admin/`. Para reinstalar desde cero: borra `config.php` y vuelve a subir `install.php` del ZIP. |
| Olvidé la contraseña del panel | Borra `config.php` desde el Administrador de archivos, sube de nuevo `install.php` del ZIP y vuelve a instalar con **la misma base de datos**: se conservan las donaciones y se pone la nueva contraseña. Después vuelve a revisar *Conexiones*. |
| *«Demasiados intentos fallidos»* | Por seguridad, espera 15 minutos. |
| No aparece el botón de donar | Falta la clave de Stripe (panel → Conexiones) o la colecta está cerrada (Ajustes). |
| *«No pudimos iniciar el pago»* | La clave de Stripe es incorrecta o es de otra cuenta: pulsa *Probar conexión con Stripe*. |
| La barra no sube al momento | Revisa el webhook (paso 7.2): URL exacta, los 3 eventos y el `whsec_` correcto. Aun sin webhook, la web se sincroniza sola cada 10 minutos (o pulsa *Actualizar desde Stripe* en Estadísticas). |
| No llegan los correos | Panel → **Correos → Registro de correos**: el error explica la causa. Lo más común: el dominio no está verificado en Resend o el remitente no es de ese dominio. Mira también la carpeta de spam. |
| Los correos llegan a spam | Comprueba que **todos** los registros DNS de Resend están verificados. Añadir el registro DMARC que propone Resend ayuda. |
| Una comisión aparece como *«pendiente»* | Stripe tarda unos segundos en calcularla. Pulsa *Actualizar desde Stripe*. |
| El PDF no se descarga en el móvil | Prueba desde un ordenador o mantén pulsado el botón → *Descargar enlace*. |

---

## 14. Lista de comprobación final

- [ ] Cuenta de Stripe exclusiva creada y **activada** (con la cuenta bancaria de destino)
- [ ] Dominio **verificado** en Resend
- [ ] Subdominio creado con **https** (candado)
- [ ] Base de datos MySQL y usuario con todos los privilegios
- [ ] Archivos subidos y extraídos en la carpeta correcta
- [ ] Instalador ejecutado; `install.php` ya no existe
- [ ] Panel → Conexiones: Stripe y Resend comprobados
- [ ] Textos, fechas y foto revisados en Ajustes
- [ ] Donación de prueba completa (barra, correo, estadísticas, PDF)
- [ ] Claves **reales** (`sk_live_` y webhook real) puestas y comprobadas
- [ ] Redirección a https activada en `.htaccess`
- [ ] Primera actualización publicada
- [ ] Enlace compartido 💛
