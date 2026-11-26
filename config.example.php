<?php
/**
 * Copia este archivo como config.php y completa los datos.
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

    'currency' => 'eur',

    // Texto que verá el donante en el extracto de su tarjeta (máx. 22 caracteres, sin < > \ ' " *).
    'statement_descriptor_suffix' => 'SERGIO ALDERETE',

    // Contraseña del panel admin, como hash. Genérala con:
    //   php -r 'echo password_hash("tu-contraseña", PASSWORD_DEFAULT), PHP_EOL;'
    'admin_password_hash' => '',

    // Importes permitidos por donación (en euros)
    'min_amount' => 1,
    'max_amount' => 5000,
];
