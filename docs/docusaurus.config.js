/** @type {import('@docusaurus/types').Config} */
const config = {
  title: 'Localhost',
  tagline: 'Compose application catalog and service management',
  url: 'https://localhost.invalid',
  baseUrl: '/docs/',
  trailingSlash: true,
  onBrokenLinks: 'throw',
  markdown: {
    format: 'md',
  },
  presets: [
    ['classic', {
      docs: {
        path: 'pages',
        routeBasePath: '/',
        sidebarPath: './sidebars.js',
      },
      blog: false,
      pages: false,
    }],
  ],
  themeConfig: {
    navbar: {
      title: 'Localhost',
      items: [
        {href: '/', label: 'App manager', position: 'right'},
      ],
    },
  },
};

export default config;
