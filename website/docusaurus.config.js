import {themes as prismThemes} from 'prism-react-renderer';

/** @type {import('@docusaurus/types').Config} */
const config = {
  title: 'Laravel Checkpoint',
  tagline: 'Operational docs for backup, restore, drill, and safety workflows.',
  favicon: 'img/logo.svg',
  future: {
    v4: true,
  },
  url: 'https://adityaa-codes.github.io',
  baseUrl: '/laravel-checkpoint/',
  organizationName: 'adityaa-codes',
  projectName: 'laravel-checkpoint',
  onBrokenLinks: 'throw',
  trailingSlash: false,
  markdown: {
    hooks: {
      onBrokenMarkdownLinks: 'throw',
    },
  },
  i18n: {
    defaultLocale: 'en',
    locales: ['en'],
  },
  presets: [
    [
      'classic',
      ({
        docs: {
          sidebarPath: './sidebars.js',
          routeBasePath: 'docs',
          editUrl:
            'https://github.com/adityaa-codes/laravel-checkpoint/tree/main/website/',
          lastVersion: 'current',
          versions: {
            current: {
              label: 'Next',
              path: 'next',
            },
          },
        },
        blog: false,
        theme: {
          customCss: './src/css/custom.css',
        },
      }),
    ],
  ],
  themeConfig:
    ({
      colorMode: {
        defaultMode: 'light',
        disableSwitch: false,
        respectPrefersColorScheme: false,
      },
      navbar: {
        title: 'Laravel Checkpoint',
        logo: {
          alt: 'Laravel Checkpoint',
          src: 'img/logo.svg',
        },
        items: [
          {
            type: 'docSidebar',
            sidebarId: 'docsSidebar',
            position: 'left',
            label: 'Docs',
          },
          {
            type: 'docsVersionDropdown',
            position: 'left',
          },
          {
            href: 'https://packagist.org/packages/adityaa-codes/laravel-checkpoint',
            label: 'Packagist',
            position: 'right',
          },
          {
            href: 'https://github.com/adityaa-codes/laravel-checkpoint',
            label: 'GitHub',
            position: 'right',
          },
        ],
      },
      footer: {
        style: 'light',
        links: [
          {
            title: 'Docs',
            items: [
              {
                label: 'Start Here',
                to: '/docs/next/start-here',
              },
              {
                label: 'Installation',
                to: '/docs/next/getting-started/installation',
              },
              {
                label: 'Command Reference',
                to: '/docs/next/cli/command-reference',
              },
            ],
          },
          {
            title: 'Project',
            items: [
              {
                label: 'Changelog',
                href: 'https://github.com/adityaa-codes/laravel-checkpoint/blob/main/CHANGELOG.md',
              },
              {
                label: 'Upgrading',
                href: 'https://github.com/adityaa-codes/laravel-checkpoint/blob/main/UPGRADING.md',
              },
              {
                label: 'Contributing',
                href: 'https://github.com/adityaa-codes/laravel-checkpoint/blob/main/CONTRIBUTING.md',
              },
            ],
          },
          {
            title: 'Package',
            items: [
              {
                label: 'Packagist',
                href: 'https://packagist.org/packages/adityaa-codes/laravel-checkpoint',
              },
              {
                label: 'GitHub',
                href: 'https://github.com/adityaa-codes/laravel-checkpoint',
              },
            ],
          },
        ],
        copyright: `Copyright © ${new Date().getFullYear()} Laravel Checkpoint. Built with Docusaurus.`,
      },
      prism: {
        theme: prismThemes.github,
        darkTheme: prismThemes.duotoneLight,
        additionalLanguages: ['bash', 'php', 'json', 'yaml'],
      },
    }),
};

export default config;
