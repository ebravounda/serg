<?php
/**
 * Valores por defecto. NO hace falta editar este archivo:
 * el instalador (install.php) crea config.php con la base de datos,
 * y las claves de Stripe y Resend se ponen desde el panel → Conexiones.
 *
 * config.php NO se sube a git (contiene claves privadas).
 */
return [
    // Dirección pública del sitio, sin barra final. Déjalo vacío para detectarla sola.
    'site_url' => '',

    // Clave secreta de Stripe (Desarrolladores > Claves de API). sk_test_... para pruebas, sk_live_... en producción.
    'stripe_secret_key' => '',

    // Secreto del webhook (whsec_...). Recomendado: actualiza la barra al instante. Ver README.
    'stripe_webhook_secret' => '',

    // Opcional: ID de un producto creado en Stripe (prod_...) para agrupar todas las donaciones.
    'stripe_product_id' => '',

    // Identificador de la campaña. Cada pago lleva metadata campaign=<este valor>
    // y SOLO los pagos con esa etiqueta suman en la barra. No lo cambies una vez publicada.
    'campaign_id' => 'memoria-sergio-elio-alderete',

    // Correo con Resend (también configurable desde el panel → Conexiones)
    'resend_api_key' => '',
    'mail_from_email' => '',      // remitente, de un dominio verificado en Resend
    'mail_from_name' => 'Amigos de Sergio',
    'mail_reply_to' => '',        // opcional: a dónde llegan las respuestas
    'notify_email' => '',         // opcional: recibe un aviso de cada donación

    'currency' => 'eur',

    // Texto que verá el donante en el extracto de su tarjeta (máx. 22 caracteres, sin < > \ ' " *).
    'statement_descriptor_suffix' => 'SERGIO ALDERETE',

    // Opcional: limita el acceso al panel a ciertas IPs, ej. ['81.2.3.4']
    'admin_allowed_ips' => [],

    // Importes permitidos por donación (en euros)
    'min_amount' => 5,
    'max_amount' => 5000,
];
