import { __, _n, sprintf } from '@wordpress/i18n';
import { ChevronLeft, ChevronRight, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { deleteChatSession, listChatSessions, purgeChatSessions } from './api';
import type { ChatSessionSummary } from './types';

const SESSIONS_PER_PAGE = 5;
const PURGE_OLDER_THAN_DAYS = 7;

interface ConversationPickerProps {
  onLoadSession(sessionId: string): void;
  /** Bump to force a reload from the server (e.g. after the wizard creates
   *  a new conversation or after the chevron-back from the session header). */
  refreshKey?: number;
}

/**
 * Standalone conversation picker. Provider/credential management lives in a
 * separate flow — picking a provider is a global setting and does not start
 * or own a conversation. This component is shown when no conversation is
 * currently open so the operator can pick an existing one or start fresh.
 */
export function ConversationPicker({ onLoadSession, refreshKey = 0 }: ConversationPickerProps) {
  const [sessions, setSessions] = useState<ChatSessionSummary[]>([]);
  const [page, setPage] = useState<number>(1);
  const [totalPages, setTotalPages] = useState<number>(1);
  const [total, setTotal] = useState<number>(0);
  const [loading, setLoading] = useState<boolean>(false);
  const [error, setError] = useState<string>('');
  const [purging, setPurging] = useState<boolean>(false);
  const [purgeOpen, setPurgeOpen] = useState<boolean>(false);
  const [deleteTarget, setDeleteTarget] = useState<ChatSessionSummary | null>(null);
  const [deleting, setDeleting] = useState<boolean>(false);

  const refresh = useCallback(async (nextPage: number) => {
    setLoading(true);
    try {
      const result = await listChatSessions({ page: nextPage, perPage: SESSIONS_PER_PAGE });
      setSessions(result.sessions);
      setTotal(result.total);
      setTotalPages(Math.max(1, result.totalPages));
      // Clamp page if the server returned fewer pages than asked for (e.g.
      // last item on this page was deleted).
      if (nextPage > result.totalPages && result.totalPages > 0) {
        setPage(result.totalPages);
      } else {
        setPage(nextPage);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void refresh(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [refreshKey]);

  const handleConfirmDelete = useCallback(async () => {
    if (deleting || !deleteTarget) return;
    setDeleting(true);
    setError('');
    try {
      await deleteChatSession(deleteTarget.id);
      await refresh(page);
      setDeleteTarget(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setDeleting(false);
    }
  }, [deleteTarget, deleting, page, refresh]);

  const handleConfirmPurge = useCallback(async () => {
    if (purging) return;
    setPurging(true);
    setError('');
    try {
      await purgeChatSessions({ olderThanDays: PURGE_OLDER_THAN_DAYS });
      await refresh(1);
      setPurgeOpen(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setPurging(false);
    }
  }, [purging, refresh]);

  return (
    <section className="pfa-conversation-picker">
      <div className="pfa-wizard__sessions-head">
        <h3>{ __('Conversation', 'wp-pfagent') }</h3>
        <button
          type="button"
          className="pfa-wizard__purge"
          onClick={() => setPurgeOpen(true)}
          disabled={purging || loading}
          title={ sprintf(__('Delete all conversations older than %d days', 'wp-pfagent'), PURGE_OLDER_THAN_DAYS) }
        >
          <Trash2 size={13} aria-hidden="true" />
          { purging ? __('Purging…', 'wp-pfagent') : sprintf(__('Purge > %dd', 'wp-pfagent'), PURGE_OLDER_THAN_DAYS) }
        </button>
      </div>
      <p className="pfa-wizard__hint">{ __('Continue a previous conversation or proceed to start a new one.', 'wp-pfagent') }</p>
      {error ? (
        <div className="pfa-banner pfa-banner--error" role="alert">
          <strong>{ __('Error:', 'wp-pfagent') }</strong> {error}
        </div>
      ) : null}
      {sessions.length === 0 && !loading ? (
        <p className="pfa-wizard__empty">{ __('No conversations yet.', 'wp-pfagent') }</p>
      ) : (
        <ul className="pfa-wizard__sessions" aria-busy={loading}>
          {sessions.map((session) => (
            <li key={session.id} className="pfa-wizard__session-row">
              <button type="button" className="pfa-wizard__session" onClick={() => onLoadSession(session.id)}>
                <strong>{session.label || `#${session.id}`}</strong>
                <span className="pfa-wizard__session-meta">
                  { sprintf(
                    _n('%1$d turn · updated %2$s', '%1$d turns · updated %2$s', session.turnCount, 'wp-pfagent'),
                    session.turnCount,
                    formatDate(session.updatedAt || session.lastTurnAt)
                  ) }
                </span>
              </button>
              <button
                type="button"
                className="pfa-wizard__session-delete"
                onClick={() => setDeleteTarget(session)}
                disabled={loading || deleting}
                aria-label={ sprintf(__('Delete conversation %s', 'wp-pfagent'), session.label || `#${session.id}`) }
                title={ __('Delete conversation', 'wp-pfagent') }
              >
                <Trash2 size={14} aria-hidden="true" />
              </button>
            </li>
          ))}
        </ul>
      )}
      {totalPages > 1 ? (
        <nav className="pfa-wizard__pagination" aria-label={ __('Conversation pages', 'wp-pfagent') }>
          <button
            type="button"
            className="pfa-wizard__pager"
            onClick={() => void refresh(Math.max(1, page - 1))}
            disabled={loading || page <= 1}
            aria-label={ __('Previous page', 'wp-pfagent') }
          >
            <ChevronLeft size={14} aria-hidden="true" />
          </button>
          <span className="pfa-wizard__page-label">
            { sprintf(__('Page %1$d of %2$d', 'wp-pfagent'), page, totalPages) }
          </span>
          <button
            type="button"
            className="pfa-wizard__pager"
            onClick={() => void refresh(Math.min(totalPages, page + 1))}
            disabled={loading || page >= totalPages}
            aria-label={ __('Next page', 'wp-pfagent') }
          >
            <ChevronRight size={14} aria-hidden="true" />
          </button>
        </nav>
      ) : null}

      {deleteTarget ? (
        <div
          className="pfa-modal-backdrop"
          role="presentation"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget && !deleting) {
              setDeleteTarget(null);
            }
          }}
        >
          <div className="pfa-modal" role="dialog" aria-modal="true" aria-labelledby="pfa-conv-delete-title">
            <header className="pfa-modal__header">
              <div className="pfa-modal__icon pfa-modal__icon--danger" aria-hidden="true">
                <Trash2 size={16} />
              </div>
              <h2 id="pfa-conv-delete-title">{ __('Delete conversation', 'wp-pfagent') }</h2>
            </header>
            <p className="pfa-modal__body">
              { sprintf(
                /* translators: %s: conversation label */
                __('"%s" will be permanently deleted along with its messages. This action cannot be undone.', 'wp-pfagent'),
                deleteTarget.label || `#${deleteTarget.id}`
              ) }
            </p>
            <footer className="pfa-modal__actions">
              <button type="button" className="pfa-wizard__cancel" onClick={() => setDeleteTarget(null)} disabled={deleting}>
                { __('Cancel', 'wp-pfagent') }
              </button>
              <button
                type="button"
                className="pfa-modal__danger"
                onClick={() => void handleConfirmDelete()}
                disabled={deleting}
                autoFocus
              >
                <Trash2 size={13} aria-hidden="true" />
                { deleting ? __('Deleting…', 'wp-pfagent') : __('Delete', 'wp-pfagent') }
              </button>
            </footer>
          </div>
        </div>
      ) : null}

      {purgeOpen ? (
        <div
          className="pfa-modal-backdrop"
          role="presentation"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget && !purging) {
              setPurgeOpen(false);
            }
          }}
        >
          <div className="pfa-modal" role="dialog" aria-modal="true" aria-labelledby="pfa-conv-purge-title">
            <header className="pfa-modal__header">
              <div className="pfa-modal__icon pfa-modal__icon--danger" aria-hidden="true">
                <Trash2 size={16} />
              </div>
              <h2 id="pfa-conv-purge-title">{ __('Purge old conversations', 'wp-pfagent') }</h2>
            </header>
            <p className="pfa-modal__body">
              { sprintf(
                /* translators: %d: days threshold */
                __('All conversations whose last activity is older than %d days will be permanently deleted. This action cannot be undone.', 'wp-pfagent'),
                PURGE_OLDER_THAN_DAYS
              ) }
            </p>
            <footer className="pfa-modal__actions">
              <button type="button" className="pfa-wizard__cancel" onClick={() => setPurgeOpen(false)} disabled={purging}>
                { __('Cancel', 'wp-pfagent') }
              </button>
              <button
                type="button"
                className="pfa-modal__danger"
                onClick={() => void handleConfirmPurge()}
                disabled={purging}
                autoFocus
              >
                <Trash2 size={13} aria-hidden="true" />
                { purging ? __('Purging…', 'wp-pfagent') : __('Delete conversations', 'wp-pfagent') }
              </button>
            </footer>
          </div>
        </div>
      ) : null}

      {total > 0 ? null : null}
    </section>
  );
}

function formatDate(iso: string): string {
  if (!iso) return 'never';
  try {
    const date = new Date(iso);
    if (Number.isNaN(date.valueOf())) return iso;
    return date.toLocaleString();
  } catch {
    return iso;
  }
}
