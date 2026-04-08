/** @type {import('@docusaurus/plugin-content-docs').SidebarsConfig} */
const sidebars = {
  docsSidebar: [
    'start-here',
    {
      type: 'category',
      label: 'Getting Started',
      items: [
        'getting-started/installation',
        'getting-started/quickstart',
      ],
    },
    {
      type: 'category',
      label: 'Common Tasks',
      items: [
        'common-tasks/take-your-first-backup',
        'common-tasks/check-health-and-status',
        'common-tasks/restore-a-backup',
        'common-tasks/run-a-drill',
      ],
    },
    {
      type: 'category',
      label: 'Configuration',
      items: [
        'configuration/basic-configuration',
        'configuration/queue-timeouts',
      ],
    },
    {
      type: 'category',
      label: 'Drivers',
      items: [
        'drivers/choose-a-driver',
      ],
    },
    {
      type: 'category',
      label: 'Reference',
      items: [
        'cli/command-reference',
      ],
    },
    {
      type: 'category',
      label: 'Safety',
      items: [
        'safety/restore-guardrails',
      ],
    },
    {
      type: 'category',
      label: 'Troubleshooting',
      items: [
        'troubleshooting/common-failures',
      ],
    },
    {
      type: 'category',
      label: 'Contributing',
      items: [
        'contributing/index',
      ],
    },
  ],
};

export default sidebars;
