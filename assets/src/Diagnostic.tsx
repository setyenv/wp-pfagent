import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from 'react';

import { getAgentMetrics, getBetaReadiness } from './api';
import type { AgentMetricsResponse, BetaReadinessReport } from './types';

export function Diagnostic() {
  const [report, setReport] = useState<BetaReadinessReport | null>(null);
  const [metrics, setMetrics] = useState<AgentMetricsResponse | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [readiness, metricsResponse] = await Promise.all([getBetaReadiness(), getAgentMetrics(24)]);
      setReport(readiness);
      setMetrics(metricsResponse);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  if (loading) {
    return (
      <section className="pfa-diagnostic" data-testid="diagnostic">
        <header className="pfa-diagnostic__header">
          <h2>{ __('Diagnostic', 'wp-pfagent') }</h2>
        </header>
        <p className="pfa-inspector__hint">{ __('Loading beta readiness report…', 'wp-pfagent') }</p>
      </section>
    );
  }

  if (error) {
    return (
      <section className="pfa-diagnostic" data-testid="diagnostic">
        <header className="pfa-diagnostic__header">
          <h2>{ __('Diagnostic', 'wp-pfagent') }</h2>
          <span className="pfa-inspector__status pfa-inspector__status--failed" data-testid="diagnostic-status">error</span>
        </header>
        <div className="pfa-banner pfa-banner--error" role="alert">
          <strong>{ __('Error:', 'wp-pfagent') }</strong> {error}
        </div>
      </section>
    );
  }

  if (!report) {
    return null;
  }

  const status = report.ready ? 'completed' : 'confirmation';
  const statusLabel = report.ready ? __('beta ready', 'wp-pfagent') : __('beta partial', 'wp-pfagent');

  return (
    <section className="pfa-diagnostic" data-testid="diagnostic">
      <header className="pfa-diagnostic__header">
        <h2>{ __('Diagnostic', 'wp-pfagent') }</h2>
        <span className={`pfa-inspector__status pfa-inspector__status--${status}`} data-testid="diagnostic-status">
          {statusLabel}
        </span>
        <button
          type="button"
          className="pfa-diagnostic__refresh"
          onClick={() => void load()}
          disabled={loading}
          data-testid="diagnostic-refresh"
          aria-label={ __('Refresh diagnostic', 'wp-pfagent') }
        >
          {loading ? '...' : __('Refresh', 'wp-pfagent')}
        </button>
      </header>

      <p className="pfa-inspector__hint">
        { sprintf(__('Generated at %s.', 'wp-pfagent'), report.generatedAt.replace('T', ' ').replace('Z', ' UTC')) }
      </p>

      {metrics ? (
        <section className="pfa-diagnostic__section" data-testid="diagnostic-cost">
          <h4>{ sprintf(__('Cost and tokens (last %dh)', 'wp-pfagent'), metrics.windowHours) }</h4>
          <ul className="pfa-diagnostic__cost">
            <li>
              <strong>{ __('Total tokens', 'wp-pfagent') }</strong><span>{metrics.totals.totalTokens.toLocaleString()}</span>
            </li>
            <li>
              <strong>{ __('Estimated cost', 'wp-pfagent') }</strong><span>{formatCost(metrics.totals.totalCostMicros)}</span>
            </li>
            <li>
              <strong>{ __('Events recorded', 'wp-pfagent') }</strong><span>{metrics.totals.totalRows}</span>
            </li>
          </ul>
          {Object.keys(metrics.totals.tokensByProvider).length > 0 ? (
            <details>
              <summary>{ __('Per provider', 'wp-pfagent') }</summary>
              <ul>
                {Object.entries(metrics.totals.tokensByProvider).map(([providerId, tokens]) => {
                  const cost = metrics.totals.costMicrosByProvider[providerId] ?? 0;
                  return (
                    <li key={providerId || '_unknown'}>
                      <code>{providerId || '_unknown'}</code>: { sprintf(__('%1$s tokens, %2$s', 'wp-pfagent'), tokens.toLocaleString(), formatCost(cost)) }
                    </li>
                  );
                })}
              </ul>
            </details>
          ) : null}
        </section>
      ) : null}

      <section className="pfa-diagnostic__section">
        <h4>{ __('Severe criteria', 'wp-pfagent') }</h4>
        <ul className="pfa-diagnostic__criteria" data-testid="diagnostic-criteria">
          {Object.entries(report.criteria).map(([key, criterion]) => (
            <li key={key} className={`pfa-diagnostic__criterion pfa-diagnostic__criterion--${criterion.pass ? 'pass' : 'fail'}`}>
              <span className="pfa-diagnostic__criterion-flag">{criterion.pass ? __('PASS', 'wp-pfagent') : __('FAIL', 'wp-pfagent')}</span>
              <strong>{key}</strong>
              <p>{criterion.description}</p>
              {criterion.violations.length > 0 ? (
                <details>
                  <summary>{ sprintf(_n('%d issue', '%d issues', criterion.violations.length, 'wp-pfagent'), criterion.violations.length) }</summary>
                  <pre>{JSON.stringify(criterion.violations, null, 2)}</pre>
                </details>
              ) : null}
            </li>
          ))}
        </ul>
      </section>

      <section className="pfa-diagnostic__section">
        <h4>{ __('Capability matrix', 'wp-pfagent') }</h4>
        <ul className="pfa-diagnostic__totals" data-testid="diagnostic-totals">
          {Object.entries(report.totals).map(([state, count]) => (
            <li key={state}>
              <strong>{state}</strong>: {count}
            </li>
          ))}
        </ul>
      </section>

      {report.partial.length > 0 ? (
        <section className="pfa-diagnostic__section">
          <h4>{ __('Partial capabilities', 'wp-pfagent') }</h4>
          <ul className="pfa-diagnostic__partial">
            {report.partial.map((entry) => (
              <li key={entry.key} className={`pfa-diagnostic__partial-item pfa-diagnostic__partial-item--${entry.state}`}>
                <strong>{entry.key}</strong>
                <span>{entry.state}</span>
                {entry.notes ? <p>{entry.notes}</p> : null}
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <section className="pfa-diagnostic__section">
        <h4>{ __('Workflow dependency', 'wp-pfagent') }</h4>
        <p>
          {report.workflow.active === true
            ? sprintf(__('Active (%s)', 'wp-pfagent'), report.workflow.namespace ?? 'wp-pfworkflow/v1')
            : __('Inactive (fallback in use)', 'wp-pfagent')}
        </p>
      </section>

      <section className="pfa-diagnostic__section">
        <h4>{ sprintf(__('Configured providers (%d)', 'wp-pfagent'), report.providers.length) }</h4>
        {report.providers.length === 0 ? (
          <p className="pfa-inspector__hint">{ __('No credentials stored yet. Configure one from Settings.', 'wp-pfagent') }</p>
        ) : (
          <ul className="pfa-diagnostic__providers">
            {report.providers.map((provider, index) => (
              <li key={String(provider.providerId ?? index)}>
                <strong>{String(provider.providerId ?? '?')}</strong>
                <span>{String(provider.status ?? '')}</span>
                <code>{String(provider.maskedKey ?? '****')}</code>
              </li>
            ))}
          </ul>
        )}
      </section>
    </section>
  );
}

function formatCost(micros: number): string {
  if (!Number.isFinite(micros) || micros <= 0) {
    return __('$0 (no pricing configured)', 'wp-pfagent');
  }
  const dollars = micros / 1000000;
  return `$${dollars.toFixed(dollars < 1 ? 4 : 2)}`;
}
