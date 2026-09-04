import starlight from '@astrojs/starlight';
import { defineConfig } from 'astro/config';
import starlightThemeNova from 'starlight-theme-nova';

export default defineConfig({
  site: 'https://foundation.nexcess.dev',
  integrations: [
    starlight({
      title: 'Foundation',
      description: 'Shared PHP infrastructure for Nexcess libraries and WordPress plugins.',
      favicon: '/favicon.svg',
      customCss: ['./src/styles/custom.css'],
      editLink: {
        baseUrl: 'https://github.com/stellarwp/foundation/edit/main/src/Docs/',
      },
      lastUpdated: true,
      plugins: [starlightThemeNova()],
      sidebar: [
        {
          label: 'Start Here',
          items: [
            { slug: 'start/what-is-foundation' },
            { slug: 'start/install-foundation' },
            { slug: 'start/configure-the-container' },
            { slug: 'start/bootstrap-wordpress-plugin' },
            { slug: 'start/register-service-providers' },
            { slug: 'start/scope-foundation' },
            { slug: 'start/test-the-application' },
          ],
        },
        {
          label: 'Components',
          items: [
            { slug: 'components/container' },
            {
              label: 'Database',
              collapsed: false,
              items: [
                { slug: 'components/database', label: 'Overview' },
                { slug: 'components/database/migrations' },
                { slug: 'components/database/query-builder' },
                { slug: 'components/database/lock' },
              ],
            },
            { slug: 'components/lock' },
            { slug: 'components/log' },
            { slug: 'components/identifier' },
            { slug: 'components/pipeline' },
            { slug: 'components/shutdown' },
            { slug: 'components/view' },
            { slug: 'components/wp-cli' },
          ],
        },
        {
          label: 'Developer Tooling',
          items: [{ slug: 'tooling/foundation-cli' }],
        },
      ],
      social: [
        {
          icon: 'github',
          label: 'Foundation on GitHub',
          href: 'https://github.com/stellarwp/foundation',
        },
      ],
    }),
  ],
});
