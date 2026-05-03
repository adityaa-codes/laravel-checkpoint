import Link from '@docusaurus/Link';
import Layout from '@theme/Layout';

import styles from './index.module.css';

const layers = [
  {
    title: 'Backup',
    description: 'Queue-driven, async database dumps to S3 or local storage. Scheduled, auditable, and driver-agnostic.',
  },
  {
    title: 'Recovery',
    description: 'Point-in-time recovery (PITR), replication sync, and restore guardrails with environment-aware safety gates.',
  },
  {
    title: 'Verification',
    description: 'Automated recovery drills, 30+ health checks, gate profiles, and audit trails. Prove your backups actually work.',
  },
];

const audience = [
  { label: 'Teams on VPS', detail: 'You run Postgres or MySQL on a VPS and need reliable recovery without RDS pricing.' },
  { label: 'Spatie graduates', detail: 'You use laravel-backup for dumps. You need PITR, drills, and restore verification it does not provide.' },
  { label: 'Compliance needs', detail: 'Auditors ask "when did you last test restore?" Checkpoint answers that with drill evidence.' },
];

export default function Home() {
  return (
    <Layout
      title="Laravel Checkpoint — Database Reliability Layer"
      description="Queue-driven backup, PITR, recovery drills, and safety gates for Laravel applications.">
      <main className={styles.page}>
        <section className={styles.hero}>
          <div className={styles.heroInner}>
            <p className={styles.kicker}>Database Reliability Layer for Laravel</p>
            <h1 className={styles.title}>Your backups should actually work.</h1>
            <p className={styles.lead}>
              Checkpoint adds recovery verification, PITR, and automated drills on top of your existing backup stack.
              It does not replace laravel-backup — it makes recovery reliable.
            </p>
            <div className={styles.actions}>
              <Link className="button button--primary button--lg" to="/docs/next/start-here">
                Get started
              </Link>
              <Link
                className="button button--secondary button--lg"
                href="https://github.com/adityaa-codes/laravel-checkpoint">
                GitHub
              </Link>
            </div>
          </div>
        </section>

        <section className={styles.gridSection}>
          <div className={styles.card}>
            <p className={styles.cardEyebrow}>The 3-layer model</p>
            <h2>What Checkpoint provides</h2>
            {layers.map(layer => (
              <div key={layer.title} style={{ marginBottom: '1rem' }}>
                <strong>{layer.title}</strong>
                <p style={{ margin: '0.25rem 0 0 0' }}>{layer.description}</p>
              </div>
            ))}
          </div>

          <div className={styles.card}>
            <p className={styles.cardEyebrow}>Who this is for</p>
            <h2>Built for</h2>
            {audience.map(item => (
              <div key={item.label} style={{ marginBottom: '0.75rem' }}>
                <strong>{item.label}</strong>
                <p style={{ margin: '0.25rem 0 0 0' }}>{item.detail}</p>
              </div>
            ))}
          </div>
        </section>

        <section className={styles.gridSection} style={{ marginTop: '2rem' }}>
          <div className={styles.card}>
            <p className={styles.cardEyebrow}>Quick start</p>
            <h2>5 minutes to your first verified backup</h2>
            <pre className={styles.codeBlock}>
              <code>{`composer require adityaa-codes/laravel-checkpoint
php artisan checkpoint:install --preset=minimal
php artisan checkpoint:enqueue-backup --sync
php artisan checkpoint:doctor`}</code>
            </pre>
          </div>
        </section>
      </main>
    </Layout>
  );
}
