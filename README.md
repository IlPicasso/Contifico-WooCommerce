# Contifico WooCommerce

Integración base entre WooCommerce y Contifico. El plugin incluye herramientas para sincronizar productos, gestionar inventario y emitir documentos contables a través de la API de Contifico.

## Cliente de API

El cliente HTTP se encuentra en `includes/api/class-contifico-client.php` y encapsula la comunicación con Contifico utilizando las credenciales configuradas desde el panel de administración.

### Endpoints soportados

| Método | Endpoint                         | Descripción |
| ------ | -------------------------------- | ----------- |
| GET    | `/producto/`                      | Obtiene el catálogo de productos disponible en Contifico. |
| GET    | `/bodega/`                        | Lista las bodegas configuradas en Contifico. |
| POST   | `/documento/`                     | Registra un documento contable (factura, nota de venta, etc.). |
| POST   | `/movimiento-inventario/`         | Registra un movimiento de inventario para ajustar existencias. |

Todas las solicitudes se realizan contra `https://api.contifico.com/sistema/api/v1` (puede sobrescribirse en los ajustes) y envían el API Key configurado en el encabezado `Authorization`. Cada método devuelve un arreglo con la respuesta JSON de la API o un objeto `WP_Error` ante fallos. El cliente registra los errores mediante `wc_get_logger` (si está disponible) o `error_log` y reintenta automáticamente las solicitudes ante códigos HTTP temporales (`408`, `425`, `429`, `500`, `502`, `503`, `504`).

El método `update_stock` crea internamente un movimiento de inventario de tipo `AJU` usando el endpoint oficial de Contifico para `movimiento-inventario`. Si no se proporciona un identificador de bodega en el payload, utilizará el valor configurado en los ajustes del plugin.

## Desarrollo

### Dependencias de desarrollo

Instala las dependencias de Composer para ejecutar las pruebas unitarias:

```bash
composer install
```

### Pruebas

El proyecto incluye pruebas básicas del cliente de API utilizando Brain\Monkey. Puedes ejecutarlas con:

```bash
composer test
```

Las pruebas se encuentran en el directorio `tests/` e incluyen dobles de WordPress para `WP_Error` y funciones comunes.
