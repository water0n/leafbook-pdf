=== LeafBook PDF ===
Version: 1.6.0

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
│       ├── pdf.min.js                ← PDF.js local
│       ├── pdf.worker.min.js         ← Worker local de PDF.js
│       └── visor.js                  ← Lector PDF simple
└── readme.txt                        ← Este archivo

== INSTALACIÓN ==

1. Sube la carpeta "flipbook-manager" a /wp-content/plugins/
2. Ve a WordPress Admin → Plugins → Activar "LeafBook PDF"
3. Veras "Flipbooks" en el menu lateral

== USO ==

1. Ve a Flipbooks → Agregar nuevo
2. Escribe el título de tu revista
3. En "Configuración del Flipbook" pega la URL de tu PDF
4. Publica el flipbook
5. Copia el shortcode que aparece: [flipbook id="X"]
6. Pega ese shortcode en cualquier página o entrada

== ESTADO ==

- CPT, admin, shortcode y bloque Gutenberg.
- Lector PDF.js local sin dependencia de CDN.
- Navegacion por botones, teclado y swipe movil.
- Zoom de alta calidad por pinch o Ctrl/trackpad wheel.
- Pantalla completa en desktop y movil.
