import Link from '@docusaurus/Link';
import Layout from '@theme/Layout';

import styles from './index.module.css';

const capabilities = [
  'Queue-driven backup and restore orchestration',
  'Driver support for shell, pgBackRest, pgDump, and MySQL',
  'Status, doctor, report, catalog, and PITR-readiness commands',
  'Restore and replication guardrails for risky workflows',
];

export default function Home() {
  return (
    <Layout
      title="Laravel Checkpoint Docs"
      description="Operational documentation for the Laravel Checkpoint package.">
      <main className={styles.page}>
        <section className={styles.hero}>
          <div className={styles.heroInner}>
            <p className={styles.kicker}>Laravel package documentation</p>
            <h1 className={styles.title}>Run backup and restore workflows with guardrails.</h1>
            <p className={styles.lead}>
              Laravel Checkpoint is a package for queueable database operations,
              restore safety, drill evidence, and operator-facing diagnostics.
            </p>
            <div className={styles.actions}>
              <Link className="button button--primary button--lg" to="/docs/next/start-here">
                Open docs
              </Link>
              <Link
                className="button button--secondary button--lg"
                href="https://github.com/adityaa-codes/laravel-checkpoint">
                View repository
              </Link>
            </div>
          </div>
        </section>

        <section className={styles.gridSection}>
          <div className={styles.card}>
            <p className={styles.cardEyebrow}>Current surface</p>
            <h2>What the package ships</h2>
            <ul className={styles.list}>
              {capabilities.map(item => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          </div>

          <div className={styles.card}>
            <p className={styles.cardEyebrow}>Recommended path</p>
            <h2>Start here</h2>
            <p>
              Install the package, align the queue timeout with the selected
              driver, then validate the runtime with the operator commands.
            </p>
            <pre className={styles.codeBlock}>
              <code>{`php artisan db-ops:enqueue-backup
php artisan db-ops:status --summary
php artisan db-ops:doctor`}</code>
            </pre>
          </div>
        </section>
      </main>
    </Layout>
  );
}
