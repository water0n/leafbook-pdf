=== Flipbook Manager ===
Versión: 1.0.0

== ESTRUCTURA DEL PLUGIN ==

flipbook-manager/
├── flipbook-manager.php              ← Archivo principal (punto de entrada)
├── blocks/
│   └── flipbook-block/
│       ├── block.json                ← Metadatos del bloque Gutenberg
│       ├── index.js                  ← Script del editor (interfaz visual)
│       └── editor.css                ← Estilos dentro del editor
├── includes/
│   ├── class-flipbook-cpt.php        ← Tipo de contenido "Flipbook"
│   ├── class-flipbook-admin.php      ← Panel de administración + meta boxes
│   ├── class-flipbook-shortcode.php  ← Shortcode [flipbook id="X"]
│   └── class-flipbook-block.php      ← Registro y render del bloque Gutenberg
├── assets/
│   ├── css/
│   │   └── visor.css                 ← Estilos del visor (frontend)
│   └── js/
│       └── visor.js                  ← PDF.js + StPageFlip (efecto flip)
└── readme.txt                        ← Este archivo

== INSTALACIÓN ==

1. Sube la carpeta "flipbook-manager" a /wp-content/plugins/
2. Ve a WordPress Admin → Plugins → Activar "Flipbook Manager"
3. Verás "Flipbooks" en el menú lateral

== USO ==

1. Ve a Flipbooks → Agregar nuevo
2. Escribe el título de tu revista
3. En "Configuración del Flipbook" pega la URL de tu PDF
4. Publica el flipbook
5. Copia el shortcode que aparece: [flipbook id="X"]
6. Pega ese shortcode en cualquier página o entrada

== ROADMAP ==

✅ Fase 1: Estructura base, CPT, admin, shortcode
✅ Fase 2: Visor con PDF.js + efecto flip con StPageFlip
✅ Fase 3: Bloque Gutenberg nativo
✅ Fase 4: Swipe móvil, zoom, miniaturas, descarga, barra de progreso
🔜 Fase 5 (ideas): lightbox, tabla de contenidos editable, analytics de páginas vistas
