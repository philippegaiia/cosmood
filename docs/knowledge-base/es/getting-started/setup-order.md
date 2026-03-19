---
title: Orden de configuración
id: getting-started/setup-order
order: 1
---

Esta página explica el orden más seguro para configurar los datos y evitar bloqueos más adelante.

## Orden recomendado

1. categorías de ingredientes,
2. ingredientes,
3. proveedores,
4. referencias proveedor-ingrediente,
5. categorías de productos,
6. líneas de producción y festivos,
7. tipos de producto,
8. fórmulas,
9. plantillas de QC y de tareas,
10. productos,
11. producciones u oleadas.

## Por qué este orden es importante

- Un producto depende de su tipo de producto.
- Un pedido a proveedor útil depende de ingredientes ya referenciados.
- La planificación funciona mucho mejor si las líneas, las plantillas y las reglas de QC ya existen.

## Errores frecuentes

- Crear un producto antes de haber creado su tipo de producto.
- Crear una producción sin fórmula o sin un marco de planificación claro.
- Intentar comprar un ingrediente que todavía no está bien vinculado al proveedor correcto.
