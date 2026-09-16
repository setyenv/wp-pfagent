import { __, _n, sprintf } from '@wordpress/i18n';
import {
  Bot,
  Check,
  ChevronLeft,
  ChevronRight,
  Pencil
} from 'lucide-react';
import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';

import { ConversationPicker } from './ConversationPicker';
import { Diagnostic } from './Diagnostic';
import { Markdown } from './Markdown';
import {
  describeWpExecution,
  isWpTool,
  wpAdminUrlForTarget,
  wpTargetFromExecutions,
  type WpActivityItem,
  type WpTarget,
} from './wpTarget';
import { WordPressPane, WordPressTabButton } from './WordPressPane';
import {
  agentTurn,
  appendChatMessages,
  createChatSession,
  getChatSession,
  getConfig,
  listProviderCredentials,
  listTemplates,
  listWorkflows,
  patchChatSession,
  setActiveLlm
} from './api';
import { agentResume, agentProgress, agentContinue } from './api';
import type { ProviderCredentialStatus } from './types';
import { ProviderWizard } from './ProviderWizard';
import { ProductSwitcher } from './ProductSwitcher';
import type {
  AgentRuntimeExecution,
  AgentRuntimeProgressNarration,
  AgentRuntimeProgressTool,
  AgentRuntimeTurnResult,
  ChatMessage,
  ChatSessionMessage
} from './types';

// Legacy localStorage keys we proactively clear when migrating; the chat
// history key polluted post-rewrite empty states with command-parser
// messages from before the LLM agent integration. The legacy active-llm
// key is also wiped on boot because the active-LLM selection has been
// promoted to a server-persisted wp_option (single source of truth, read
// from the bootstrap config and written via the /active-llm REST route).
const legacyChatHistoryKey = 'wp-pfagent.chat-history.v1';
const legacyActiveLlmKey = 'wp-pfagent.active-llm.v1';
const activeStateKey = 'wp-pfagent.active.v1';
const iframeStateKey = 'wp-pfagent.iframe.v1';

interface ActiveState {
  providerId: string;
  model: string;
  sessionId: string | null;
}

// 'wordpress' is the transversal tab: it reflects the agent's DIRECT actions on
// WP core / third-party plugins. Unlike pfm/pfw it has no dependency gate - WP
// core is always present - so it is always shown, after pfm/pfw. Additive: the
// pfm/pfw reflection path is unchanged.
type IframeTab = 'pfm' | 'pfw' | 'wordpress';

interface IframeState {
  activeTab: IframeTab | null;
  pfmUrl: string | null;
  pfwUrl: string | null;
  wpUrl: string | null;
}

interface IframeViewController {
  /** Activate a tab and optionally point it at a new URL. The iframe stays
   *  mounted whether the tab is visible or not, so switching is non-destructive. */
  show(tab: IframeTab, url?: string | null): void;
  /** Replace the URL of a tab without changing the active tab. */
  setUrl(tab: IframeTab, url: string | null): void;
  /** Snapshot of the current iframe state (for debugging from the console). */
  snapshot(): IframeState;
}

declare global {
  interface Window {
    ProjectFlashAgentIframe?: IframeViewController;
  }
}

function loadActiveState(): ActiveState | null {
  // Wipe legacy keys so a fresh load doesn't surface stale parser messages
  // (chat history) nor a stale browser-local LLM selection that no longer
  // counts as source of truth.
  try {
    localStorage.removeItem(legacyChatHistoryKey);
    localStorage.removeItem(legacyActiveLlmKey);
    localStorage.removeItem(activeStateKey);
  } catch {
    /* swallow */
  }

  // Hydrate from the server-injected bootstrap config (wp_pfagent_active_llm
  // option). The session id is never restored at boot: the app always lands
  // on the picker so the operator explicitly chooses or starts a session.
  const bootstrap = getConfig().activeLlm;
  if (
    bootstrap &&
    typeof bootstrap.providerId === 'string' &&
    typeof bootstrap.model === 'string' &&
    bootstrap.providerId &&
    bootstrap.model
  ) {
    return {
      providerId: bootstrap.providerId,
      model: bootstrap.model,
      sessionId: null,
    };
  }

  return null;
}

function persistActiveState(state: ActiveState): void {
  // Fire-and-forget: the wp_pfagent_active_llm option is the single source
  // of truth; the next page load reads from the bootstrap config which the
  // server will reflect. Errors are swallowed so a transient REST failure
  // doesn't block the UI — the in-memory state still drives the screen.
  void setActiveLlm({
    providerId: state.providerId,
    model: state.model,
    sessionId: state.sessionId,
  }).catch(() => {
    /* swallow */
  });
}

function loadIframeState(): Partial<IframeState> {
  try {
    const raw = localStorage.getItem(iframeStateKey);
    if (!raw) {
      return {};
    }
    const parsed = JSON.parse(raw) as Partial<IframeState>;
    return {
      // The tab preference DOES survive reloads — the operator
      // expects to land on whichever tab they were looking at.
      activeTab:
        parsed.activeTab === 'pfm' || parsed.activeTab === 'pfw' || parsed.activeTab === 'wordpress'
          ? parsed.activeTab
          : null,
      // URLs do NOT survive: stripping the resource-pinning
      // parameters here means a fresh wp-pfagent boot lands the
      // iframes on the bare landing pages of pfm / pfw instead of
      // restoring whatever workflow / entity the LLM had focused
      // before. Restoring those produced the operator's reported
      // surprise — "the moment I open wp-pfagent I see a workflow
      // loaded, this is not right". The agent's next tool call
      // will repopulate the URL via the iframe controller when it
      // does something focusable.
      pfmUrl: typeof parsed.pfmUrl === 'string' ? stripFocusParams(parsed.pfmUrl) : null,
      pfwUrl: typeof parsed.pfwUrl === 'string' ? stripFocusParams(parsed.pfwUrl) : null,
    };
  } catch {
    return {};
  }
}

/** Remove the per-resource focus parameters from a previously-
 *  persisted iframe URL so each fresh page load starts on the
 *  destination plugin's landing page. Keeps `pfa_preview` so the
 *  read-only focus CSS still applies. */
function stripFocusParams(url: string): string {
  try {
    const u = new URL(url, window.location.origin);
    const keep = ['page', 'pfa_preview'];
    const toDelete: string[] = [];
    u.searchParams.forEach((_, key) => {
      if (!keep.includes(key)) toDelete.push(key);
    });
    for (const k of toDelete) u.searchParams.delete(k);
    return u.toString();
  } catch {
    return url;
  }
}

type ApiCheckStatus = 'idle' | 'skipped' | 'checking' | 'ok' | 'failed';

interface WorkflowApiHealth {
  workflows: ApiCheckStatus;
  templates: ApiCheckStatus;
  lastCheckedAt: string | null;
  message: string;
}

export function App() {
  const config = getConfig();
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [workflowHealth, setWorkflowHealth] = useState<WorkflowApiHealth>(() => ({
    workflows: 'idle',
    templates: 'idle',
    lastCheckedAt: null,
    message: __('Workflow API has not been checked yet.', 'wp-pfagent')
  }));
  const [messages, setMessages] = useState<ChatMessage[]>(initialBootMessages);
  const [active, setActive] = useState<ActiveState | null>(() => loadActiveState());
  const [showWizard, setShowWizard] = useState<boolean>(false);
  // When true, the wizard renders its session picker even though a provider
  // is already configured — used by the back-to-list chevron so the operator
  // can switch conversations without re-picking the LLM.
  const [showSessionsInWizard, setShowSessionsInWizard] = useState<boolean>(false);
  const [sessionLabel, setSessionLabel] = useState<string>('');
  const [editingLabel, setEditingLabel] = useState<boolean>(false);
  const [labelDraft, setLabelDraft] = useState<string>('');
  const [savingLabel, setSavingLabel] = useState<boolean>(false);
  const labelInputRef = useRef<HTMLInputElement | null>(null);
  const [turnError, setTurnError] = useState<string>('');
  const [sessionHydrated, setSessionHydrated] = useState<boolean>(false);
  // True while an existing conversation is being fetched from the server
  // (picker load or mount hydration). Drives the message-area skeleton so the
  // panel shows loading placeholders instead of the previous conversation or a
  // blank void.
  const [sessionLoading, setSessionLoading] = useState<boolean>(false);
  // Last turn's prompt-token count. Surfaced as the context-usage bar
  // at the top of the chat view when the active model declares a
  // contextLength (Sprint D follow-up).
  const [latestPromptTokens, setLatestPromptTokens] = useState<number>(0);
  // Live progress trail shown to the customer while a turn is in
  // flight. Built from /agent-runtime/progress polling: each freshly-
  // completed tool or LLM round maps to a generalist, product-
  // language string in the user's locale ("Analyzing the data
  // model…", "Implementing the workflow logic…"). The trail
  // accumulates so the operator can see what the agent has been
  // doing even when a single tool ran for a minute; the active
  // (last) line is rendered with a spinner, the rest as a compact
  // history. Internal tool names are NEVER exposed.
  const [progressTrail, setProgressTrail] = useState<string[]>([]);
  // Which assistant bubbles have their executions <details> expanded, keyed by
  // the STABLE message id. #7: the executions block used to be an uncontrolled
  // native <details> whose open state lived in the DOM — while the agent worked
  // and setMessages re-rendered the list, React could reuse a sibling's DOM node
  // and the penultimate bubble's open panel got painted with the last bubble's
  // executions. Tracking open state in React, keyed by message id, makes each
  // panel's expansion survive re-renders deterministically.
  const [expandedExec, setExpandedExec] = useState<Set<string>>(() => new Set());
  // Credentials cached on this view so the chat header can look up
  // active.model's contextLength for the context-usage bar without
  // calling the REST API on every render.
  const [credentialsCache, setCredentialsCache] = useState<ProviderCredentialStatus[]>([]);
  useEffect(() => {
    void (async () => {
      try {
        const catalog = await listProviderCredentials();
        setCredentialsCache(catalog.credentials);
      } catch {
        /* swallow — bar just won't show if we can't fetch */
      }
    })();
  }, [active?.providerId, active?.model]);
  const activeModelContextLength = (() => {
    if (!active?.providerId || !active.model) return undefined;
    const cred = credentialsCache.find((c) => c.providerId === active.providerId);
    if (!cred) return undefined;
    const model = (cred.models || []).find((m) => m.id === active.model);
    return model?.contextLength;
  })();
  const composerInputRef = useRef<HTMLInputElement | null>(null);
  const messagesEndRef = useRef<HTMLDivElement | null>(null);

  // --- Iframe pane state ---
  // Both iframes (when their plugin is active) stay mounted always, even
  // when not the active tab, so the user navigation inside them is never
  // discarded. The active tab is a CSS-only toggle.
  const pfmActive = Boolean(config.managementDependency?.active);
  const pfwActive = Boolean(config.workflowDependency?.active);
  const persistedIframe = loadIframeState();
  // Both iframes ALWAYS boot with ?pfa_preview=1 so pfm/pfw render in
  // read-only focus mode from the very first paint — without the param
  // the sister plugin would show its full admin chrome (sidebar, top
  // bar, save/delete buttons) and the operator could navigate out of
  // scope. The kind/ref or workflow_id slots are filled in later by
  // inferPreviewTarget() once the LLM runs a tool; absent those, the
  // iframe sits on the plugin's default landing page but still without
  // chrome. The persisted URL is reused only if it already carries
  // pfa_preview (e.g. previous session's focused URL); otherwise we
  // append it to the base adminUrl.
  const initialPfmUrl = pfmActive
    ? ensurePfaPreviewParam(persistedIframe.pfmUrl ?? config.managementDependency?.adminUrl ?? null)
    : null;
  const initialPfwUrl = pfwActive
    ? ensurePfaPreviewParam(persistedIframe.pfwUrl ?? config.workflowDependency?.adminUrl ?? null)
    : null;
  // The WordPress iframe shows NATIVE wp-admin (no pfa_preview - that param is a
  // pfm/pfw read-only overlay; core WP has its own chrome, which is exactly what
  // we want the operator to recognise). It defaults to the wp-admin dashboard;
  // a wp_* tool re-points it via the wpTarget channel. URL is not restored
  // across reloads (same discipline as pfm/pfw), only the base landing.
  const wpAdminBase = config.adminUrl || '/wp-admin/';
  const initialWpUrl = wpAdminBase;
  // Active tab on boot is ALWAYS the workflow tab when the workflow
  // plugin is installed, regardless of which tab the operator left
  // active in their previous session. The persisted activeTab is still
  // read above (so loadIframeState's URL-strip logic runs uniformly)
  // but intentionally discarded here: the operator's mental model is
  // "I open wp-pfagent to work on workflows", and landing on the
  // management tab made them think the agent had pre-loaded something
  // they hadn't asked for. Mid-session clicks on the management tab
  // still work as ever; we only override the BOOT default.
  // Boot: workflow tab when PFW is installed (unchanged), else management,
  // else - when NEITHER suite plugin is present - the transversal WordPress
  // tab is the main surface.
  const initialActiveTab: IframeTab | null = (() => {
    if (pfwActive) return 'pfw';
    if (pfmActive) return 'pfm';
    return 'wordpress';
  })();
  const [iframeActiveTab, setIframeActiveTab] = useState<IframeTab | null>(initialActiveTab);
  // Diagnostic view in the right pane. It overlays the iframes (which stay
  // mounted underneath) instead of being a third IframeTab so the workflow /
  // management iframe navigation state is never discarded when the operator
  // checks BetaReadiness and switches back.
  const [showDiagnostic, setShowDiagnostic] = useState<boolean>(false);
  const [pfmUrl, setPfmUrl] = useState<string | null>(initialPfmUrl);
  const [pfwUrl, setPfwUrl] = useState<string | null>(initialPfwUrl);
  const [wpUrl, setWpUrl] = useState<string | null>(initialWpUrl);
  // The latest turn's WordPress actions, for the activity strip in the
  // WordPress tab. Each item links its native wp-admin screen.
  const [wpActivity, setWpActivity] = useState<WpActivityItem[]>([]);

  // Guards handleWizardConfirm against being entered twice (eg. a fast
  // double-click on the wizard's Confirm button) which would otherwise
  // race two createChatSession calls and persist to the loser session.
  const wizardConfirmInFlightRef = useRef<boolean>(false);

  // Cursor for live narration streaming. The /agent-runtime/progress
  // polling returns assistant rows the Loop has persisted since this
  // ordinal; whatever the polling pushes live we MUST NOT re-push at
  // end-of-turn (when /turn-v2 returns its own assistantTexts list
  // containing the same rows). Reset at the start of every runTurn /
  // confirmPending. -1 means "no narrations seen yet"; the server's
  // lastMessageOrdinal advances it on every poll regardless of
  // whether content was non-empty (empty rows are persisted but
  // never rendered, so their ordinal still counts as "seen").
  const lastSeenNarrationOrdinalRef = useRef<number>(-1);
  // Whether THIS turn actually pushed at least one assistant bubble via live
  // polling. Must NOT be derived from lastSeenNarrationOrdinalRef, which is
  // seeded to the conversation's high-water ordinal before any push (so it is
  // >= 0 from the start on any non-empty conversation). Deriving "did we push"
  // from that ref made a confirmation/answer with no live narration attach to
  // the PREVIOUS turn's bubble — the off-by-one that glued the approval and the
  // new answer onto the prior message. Reset per turn; set only on a real push.
  const livePushedThisTurnRef = useRef<boolean>(false);

  useEffect(() => {
    if (!active || sessionHydrated) {
      return;
    }
    if (!active.sessionId) {
      setSessionHydrated(true);
      return;
    }
    setSessionLoading(true);
    void (async () => {
      try {
        const session = await getChatSession(active.sessionId!);
        setMessages(messagesFromSession(session.messages));
        setSessionLabel(session.label || __('Untitled conversation', 'wp-pfagent'));
      } catch {
        // Session disappeared upstream — drop the dangling id and start fresh.
        const next = { ...active, sessionId: null };
        setActive(next);
        persistActiveState(next);
      } finally {
        setSessionHydrated(true);
        setSessionLoading(false);
      }
    })();
  }, [active, sessionHydrated]);

  useEffect(() => {
    void refreshCatalogs();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Auto-scroll to the latest message after every change so the user
  // doesn't have to scroll manually after each turn.
  useEffect(() => {
    if (messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }
  }, [messages, busy]);

  // Focus the composer when entering chat mode (after wizard) and after
  // any turn completes so the next prompt is just-type.
  useEffect(() => {
    if (active && active.providerId && active.model && !showWizard && !busy) {
      composerInputRef.current?.focus();
    }
  }, [active, showWizard, busy]);

  // Local-only fallback so the screen survives a reload BEFORE the user
  // creates a server-side session. Once a session is active, the server
  // is the source of truth and this is overwritten on hydrate.
  useEffect(() => {
    if (!active || active.sessionId) {
      return;
    }
    try {
      localStorage.setItem(`${activeStateKey}.local-messages`, JSON.stringify(messages.slice(-80)));
    } catch {
      /* swallow */
    }
  }, [messages, active]);

  // Persist iframe state so reloads land back on the same tab + URL.
  useEffect(() => {
    try {
      localStorage.setItem(iframeStateKey, JSON.stringify({
        activeTab: iframeActiveTab,
        pfmUrl,
        pfwUrl,
        wpUrl,
      } satisfies IframeState));
    } catch {
      /* swallow */
    }
  }, [iframeActiveTab, pfmUrl, pfwUrl, wpUrl]);

  // Expose a window-level controller so future LLM tool integrations (or
  // direct browser-console operators) can drive the iframe pane: pick the
  // active tab, replace the URL, etc. The iframe stays mounted regardless
  // of which tab is visible, so .show() never triggers a reload of the
  // non-active iframe.
  useEffect(() => {
    const controller: IframeViewController = {
      show(tab, url) {
        if (tab === 'pfm' && !pfmActive) return;
        if (tab === 'pfw' && !pfwActive) return;
        if (typeof url === 'string' && url !== '') {
          if (tab === 'pfm') setPfmUrl(url);
          if (tab === 'pfw') setPfwUrl(url);
          if (tab === 'wordpress') setWpUrl(url);
        }
        setIframeActiveTab(tab);
      },
      setUrl(tab, url) {
        if (tab === 'pfm' && !pfmActive) return;
        if (tab === 'pfw' && !pfwActive) return;
        if (tab === 'pfm') setPfmUrl(url);
        if (tab === 'pfw') setPfwUrl(url);
        if (tab === 'wordpress') setWpUrl(url);
      },
      snapshot() {
        return { activeTab: iframeActiveTab, pfmUrl, pfwUrl, wpUrl };
      },
    };
    window.ProjectFlashAgentIframe = controller;
    return () => {
      if (window.ProjectFlashAgentIframe === controller) {
        delete window.ProjectFlashAgentIframe;
      }
    };
  }, [iframeActiveTab, pfmUrl, pfwUrl, wpUrl, pfmActive, pfwActive]);

  async function refreshCatalogs(): Promise<void> {
    if (!config.workflowDependency.active) {
      setWorkflowHealth({
        workflows: 'skipped',
        templates: 'skipped',
        lastCheckedAt: new Date().toISOString(),
        message: __('Workflow plugin is not active. API calls were skipped.', 'wp-pfagent')
      });
      return;
    }

    if (!config.workflowDependency.capabilities.viewWorkflows) {
      setWorkflowHealth({
        workflows: 'skipped',
        templates: 'skipped',
        lastCheckedAt: new Date().toISOString(),
        message: __('Current user cannot view workflows. API calls were skipped.', 'wp-pfagent')
      });
      return;
    }

    setWorkflowHealth((current) => ({
      ...current,
      workflows: 'checking',
      templates: 'checking',
      message: __('Checking Workflow API endpoints…', 'wp-pfagent')
    }));

    const [workflowResult, templateResult] = await Promise.allSettled([listWorkflows(), listTemplates()]);
    const workflowOk = workflowResult.status === 'fulfilled';
    const templateOk = templateResult.status === 'fulfilled';

    const apiOkMessage = __('Workflow API responded for workflows and templates.', 'wp-pfagent');
    const apiFailMessage = [
      workflowOk ? '' : sprintf(__('Workflows failed: %s', 'wp-pfagent'), settledError(workflowResult)),
      templateOk ? '' : sprintf(__('Templates failed: %s', 'wp-pfagent'), settledError(templateResult))
    ]
      .filter(Boolean)
      .join(' ');

    setWorkflowHealth({
      workflows: workflowOk ? 'ok' : 'failed',
      templates: templateOk ? 'ok' : 'failed',
      lastCheckedAt: new Date().toISOString(),
      message: workflowOk && templateOk ? apiOkMessage : apiFailMessage
    });
  }

  async function submitChat(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const text = input.trim();
    if (!text || !active || busy) {
      return;
    }

    setInput('');
    const userMessage = message('user', text);
    setMessages((items) => [...items, userMessage]);
    // v2 path: pass the active session id as conversationId so the Loop
    // writes into the same wp_pfaf_conversations row the ChatSessions UI
    // reads. When sessionId is null the Loop auto-creates a conversation
    // (orphaned of owner; the wizard always pre-creates one via /chat-sessions
    // so this branch should be unreachable in normal use).
    await runTurn(
      {
        providerId: active.providerId,
        model: active.model,
        message: text,
        ...(active.sessionId ? { conversationId: active.sessionId } : {}),
      },
      userMessage.text
    );
  }

  // H5: render one turn/continue/resume result into the chat (narrations,
  // executions, decoration, preview focus). Shared by runTurn, confirmPending
  // and the continuation loop so a paused-and-continued turn renders exactly
  // like a single long one. `isFinal` is false for an intermediate paused
  // segment — we then suppress the status-fallback bubble (there is no answer
  // yet) but still surface any narration / tool executions it produced.
  const renderTurnResult = (result: AgentRuntimeTurnResult, isFinal: boolean) => {
    const executions: AgentRuntimeExecution[] | undefined =
      Array.isArray(result.executions) && result.executions.length > 0 ? result.executions : undefined;
    const pending =
      result.status === 'needs_confirmation' && result.confirmationId && result.tool
        ? { confirmationId: result.confirmationId, toolName: result.tool.name, args: result.tool.arguments }
        : undefined;
    const errorCodeForMessage = result.status === 'completed_with_response_error' ? result.llmError?.code : undefined;
    const tailNarrations = Array.isArray(result.assistantTexts)
      ? result.assistantTexts.filter((t) => t.ordinal > lastSeenNarrationOrdinalRef.current && t.content.trim() !== '')
      : [];
    const livePushedAny = livePushedThisTurnRef.current;
    const decoration = { executions, pending, errorCode: errorCodeForMessage } as const;

    if (tailNarrations.length > 0) {
      for (let i = 0; i < tailNarrations.length; i++) {
        lastSeenNarrationOrdinalRef.current = tailNarrations[i].ordinal;
        const isLast = i === tailNarrations.length - 1;
        if (isLast) {
          pushAssistant(tailNarrations[i].content, decoration);
        } else {
          pushAssistant(tailNarrations[i].content);
        }
      }
    } else if (livePushedAny) {
      if (executions || pending || errorCodeForMessage) {
        setMessages((items) => attachDecorationToLastAssistant(items, decoration));
      }
    } else if (result.status === 'needs_confirmation' && !result.message && pending) {
      // No narration streamed this turn: host the confirmation in a FRESH
      // bubble for THIS turn (after this turn's user message), never by
      // splicing it onto the previous turn's assistant bubble.
      pushAssistant('', decoration);
    } else if (isFinal) {
      const baseText = result.message || statusFallback(result.status);
      // A provider failure must not read like a product failure. The server
      // classifies it and sends a sentence the user can act on ("its API key
      // is not valid any more…"); showing that alone beats the old
      // "LLM error: unknown — LLM HTTP 401: {vendor json}", which blamed us
      // and told nobody what to do. The raw provider text is still in the
      // turn's errorMessage for support.
      const text =
        result.status === 'completed_with_response_error' && result.llmError?.message
          ? (executions
              // Tools DID run and the closing answer is what failed: say both.
              ? `${baseText}\n\n${result.llmError.message}`
              : result.llmError.message)
          : baseText;
      pushAssistant(text, decoration);
    } else if (executions || errorCodeForMessage) {
      // Paused segment with tool work but no narration: attach its executions
      // to the latest bubble instead of a bare status line.
      setMessages((items) => attachDecorationToLastAssistant(items, decoration));
    }

    if (typeof result.usage?.promptTokens === 'number') {
      setLatestPromptTokens(result.usage.promptTokens);
    }

    const previewTarget = inferPreviewTarget(
      executions ?? [],
      pfmActive ? config.managementDependency?.adminUrl ?? null : null,
      pfwActive ? config.workflowDependency?.adminUrl ?? null : null,
      wpAdminBase,
    );
    if (previewTarget) {
      if (previewTarget.tab === 'pfm') {
        setPfmUrl((current) => (current === previewTarget.url ? current : previewTarget.url));
      }
      if (previewTarget.tab === 'pfw') {
        setPfwUrl((current) => (current === previewTarget.url ? current : previewTarget.url));
      }
      if (previewTarget.tab === 'wordpress') {
        setWpUrl((current) => (current === previewTarget.url ? current : previewTarget.url));
      }
      setIframeActiveTab((current) => (current === previewTarget.tab ? current : previewTarget.tab));
    }

    // Turn activity strip for the WordPress tab: every wp_*, wc_*, seo_*, forms_*
    // action this turn, in run order, each linking its native wp-admin screen.
    if (executions && executions.length > 0) {
      const wpItems = executions.filter((e) => isWpTool(e.tool.name)).map(describeWpExecution).filter((x): x is WpActivityItem => x !== null);
      if (wpItems.length > 0) {
        setWpActivity(wpItems);
      }
    }
  };

  // H5: drive the transparent continuation chain. While the last result paused
  // on its time budget, POST /continue-v2 for the same conversation and render
  // each segment, until it completes / needs confirmation / errors. Bounded by
  // a client-side guard mirroring the server's maxTurns cap so it can never
  // spin forever, and never issues without a conversationId.
  const driveContinuations = async (
    result: AgentRuntimeTurnResult,
    providerId: string,
    model: string,
    appendHint: (hint: string) => void,
  ): Promise<AgentRuntimeTurnResult> => {
    let current = result;
    let guard = 0;
    while (current.continuation === true && guard < 60) {
      guard += 1;
      const conversationId = current.conversationId;
      if (!conversationId) break;
      appendHint(__('Continuing…', 'wp-pfagent'));
      current = await agentContinue({ providerId, model, conversationId });
      renderTurnResult(current, current.continuation !== true);
    }
    return current;
  };

  async function runTurn(
    payload: { providerId: string; model: string; message?: string; conversationId?: string },
    userText?: string
  ) {
    setBusy(true);
    setTurnError('');
    setProgressTrail([__('Starting…', 'wp-pfagent')]);
    // Seed the live-narration cursor to the current high-water mark
    // BEFORE polling starts. Without this seed, every assistant row
    // persisted in a previous turn (or rehydrated when the operator
    // reopens the conversation) would be re-pushed as a brand-new
    // bubble on the first poll of this turn — visible as duplicate
    // narrations in the chat. The warm-up fetch below learns the
    // true MAX(ordinal) from the server and pins both the polling
    // cursor and the dedup ref to it.
    lastSeenNarrationOrdinalRef.current = -1;
    livePushedThisTurnRef.current = false;
    let initialCursors = { sinceToolCallSeq: 0, sinceTraceSeq: 0, sinceMessageOrdinal: -1 };
    if (payload.conversationId) {
      try {
        const warmup = await agentProgress({
          conversationId: payload.conversationId,
          sinceToolCallSeq: 0,
          sinceTraceSeq: 0,
          // Bounded to fit MySQL INT UNSIGNED (max 4294967295);
          // backend's MAX(ordinal) query returns the true cursor
          // regardless of how we set the bound.
          sinceMessageOrdinal: 2147483647,
        });
        initialCursors = {
          sinceToolCallSeq: warmup.lastToolCallSeq ?? 0,
          sinceTraceSeq: warmup.lastTraceSeq ?? 0,
          sinceMessageOrdinal: warmup.lastMessageOrdinal ?? -1,
        };
        lastSeenNarrationOrdinalRef.current = initialCursors.sinceMessageOrdinal;
      } catch {
        // Warm-up is best-effort: a failed fetch falls back to the
        // legacy "-1 everywhere" behaviour and the operator may see
        // a duplicate bubble or two on this one turn.
      }
    }
    // Poll the live-progress endpoint while /turn-v2 executes synchronously
    // (a multi-round tool turn can take 1-2 minutes). Each new tool call or
    // assistant round adds a generalist, product-language line to the
    // progress trail so the operator can see the agent's activity at a
    // glance even when a single step takes a while. The internal tool name
    // itself is never exposed.
    let stopPolling = false;
    const appendHint = (hint: string) => setProgressTrail((trail) => {
      // Skip consecutive duplicates so a long-running same-kind tool
      // doesn't fill the trail with the same line.
      if (trail.length > 0 && trail[trail.length - 1] === hint) return trail;
      // Cap the visible history to avoid the bubble growing without
      // bound on very long turns.
      const next = [...trail, hint];
      return next.length > 12 ? next.slice(-12) : next;
    });
    const focusFromTool = (tool: AgentRuntimeProgressTool) => {
      const target = inferPreviewTargetFromProgressTool(
        tool,
        pfmActive ? config.managementDependency?.adminUrl ?? null : null,
        pfwActive ? config.workflowDependency?.adminUrl ?? null : null,
        wpAdminBase,
      );
      if (!target) return;
      // Only update the URL state if it actually changes — without this
      // every poll triggers setState with the same string and the
      // iframe still re-renders due to React's strict equality on the
      // src prop. Net result before this guard: the editor never
      // finished booting because we kept overwriting its src with the
      // identical value every 2 s.
      if (target.tab === 'pfm') {
        setPfmUrl((current) => (current === target.url ? current : target.url));
      }
      if (target.tab === 'pfw') {
        setPfwUrl((current) => (current === target.url ? current : target.url));
      }
      if (target.tab === 'wordpress') {
        setWpUrl((current) => (current === target.url ? current : target.url));
      }
      setIframeActiveTab((current) => (current === target.tab ? current : target.tab));
    };
    const pushNarrationLive = (narrations: AgentRuntimeProgressNarration[]) => {
      for (const n of narrations) {
        if (n.ordinal <= lastSeenNarrationOrdinalRef.current) continue;
        lastSeenNarrationOrdinalRef.current = n.ordinal;
        const text = (n.content ?? '').trim();
        if (text === '') continue;
        pushAssistant(n.content);
        livePushedThisTurnRef.current = true;
      }
    };
    const pollingTimer = payload.conversationId
      ? startProgressPolling(payload.conversationId, () => stopPolling, appendHint, focusFromTool, pushNarrationLive, initialCursors)
      : null;
    try {
      let result: AgentRuntimeTurnResult = await agentTurn(payload);
      renderTurnResult(result, result.continuation !== true);
      // H5: if the turn paused on its wall-clock budget, transparently continue
      // the SAME conversation until it actually finishes — no operator action.
      result = await driveContinuations(result, payload.providerId, payload.model, appendHint);

      void persistTurnToSession(userText ?? '', result.message || statusFallback(result.status));
    } catch (error) {
      const message = humanFailure(error);
      const code = errorCode(error);
      setTurnError(message);
      pushAssistant(sprintf(__('Agent runtime failed: %s', 'wp-pfagent'), message), { errorCode: code });
    } finally {
      stopPolling = true;
      if (pollingTimer !== null) {
        window.clearTimeout(pollingTimer);
      }
      setProgressTrail([]);
      setBusy(false);
    }
  }

  async function persistTurnToSession(userText: string, assistantText: string) {
    if (!active?.sessionId) {
      return;
    }
    const payload: ChatSessionMessage[] = [];
    if (userText) {
      payload.push({ role: 'user', content: userText, at: new Date().toISOString() });
    }
    if (assistantText) {
      payload.push({ role: 'assistant', content: assistantText, at: new Date().toISOString() });
    }
    if (payload.length === 0) {
      return;
    }
    try {
      await appendChatMessages(active.sessionId, payload);
    } catch {
      /* best-effort; chat UI keeps the messages locally even if persistence fails */
    }
  }

  async function confirmPending(confirmationId: string) {
    if (!active || !active.sessionId) {
      return;
    }
    // Clear the pending flag on the originating message before issuing the
    // resume request. Without this, the modal stays mounted in the DOM
    // after the server resumes the turn — and any subsequent operator click
    // (or scripted retry) would re-POST the same already-consumed token
    // and hit a "confirmation_not_found" error.
    setMessages((items) =>
      items.map((item) =>
        item.pending && item.pending.confirmationId === confirmationId
          ? { ...item, pending: undefined }
          : item
      )
    );
    // v2 approval flow: instead of re-calling /turn with confirmed:true, hit
    // the dedicated /agent-runtime/resume-v2 endpoint. ConversationId comes
    // from the active session (== wp_pfaf_conversations.id after Sprint C).
    setBusy(true);
    setTurnError('');
    setProgressTrail([__('Resuming…', 'wp-pfagent')]);
    // Seed narration cursor to the current high-water mark BEFORE
    // resume polling starts — same race as runTurn (see comment
    // there): without the warm-up the first poll re-pushes every
    // assistant row persisted in the previous turn as a brand-new
    // bubble.
    lastSeenNarrationOrdinalRef.current = -1;
    livePushedThisTurnRef.current = false;
    let initialCursors = { sinceToolCallSeq: 0, sinceTraceSeq: 0, sinceMessageOrdinal: -1 };
    if (active.sessionId) {
      try {
        const warmup = await agentProgress({
          conversationId: active.sessionId,
          sinceToolCallSeq: 0,
          sinceTraceSeq: 0,
          // Bounded to fit MySQL INT UNSIGNED (max 4294967295);
          // backend's MAX(ordinal) query returns the true cursor
          // regardless of how we set the bound.
          sinceMessageOrdinal: 2147483647,
        });
        initialCursors = {
          sinceToolCallSeq: warmup.lastToolCallSeq ?? 0,
          sinceTraceSeq: warmup.lastTraceSeq ?? 0,
          sinceMessageOrdinal: warmup.lastMessageOrdinal ?? -1,
        };
        lastSeenNarrationOrdinalRef.current = initialCursors.sinceMessageOrdinal;
      } catch {
        /* swallow — fall back to legacy behaviour */
      }
    }
    let stopPolling = false;
    const appendHint = (hint: string) => setProgressTrail((trail) => {
      if (trail.length > 0 && trail[trail.length - 1] === hint) return trail;
      const next = [...trail, hint];
      return next.length > 12 ? next.slice(-12) : next;
    });
    const focusFromTool = (tool: AgentRuntimeProgressTool) => {
      const target = inferPreviewTargetFromProgressTool(
        tool,
        pfmActive ? config.managementDependency?.adminUrl ?? null : null,
        pfwActive ? config.workflowDependency?.adminUrl ?? null : null,
        wpAdminBase,
      );
      if (!target) return;
      if (target.tab === 'pfm') {
        setPfmUrl((current) => (current === target.url ? current : target.url));
      }
      if (target.tab === 'pfw') {
        setPfwUrl((current) => (current === target.url ? current : target.url));
      }
      if (target.tab === 'wordpress') {
        setWpUrl((current) => (current === target.url ? current : target.url));
      }
      setIframeActiveTab((current) => (current === target.tab ? current : target.tab));
    };
    const pushNarrationLive = (narrations: AgentRuntimeProgressNarration[]) => {
      for (const n of narrations) {
        if (n.ordinal <= lastSeenNarrationOrdinalRef.current) continue;
        lastSeenNarrationOrdinalRef.current = n.ordinal;
        const text = (n.content ?? '').trim();
        if (text === '') continue;
        pushAssistant(n.content);
        livePushedThisTurnRef.current = true;
      }
    };
    const pollingTimer = active.sessionId
      ? startProgressPolling(active.sessionId, () => stopPolling, appendHint, focusFromTool, pushNarrationLive, initialCursors)
      : null;
    try {
      let result: AgentRuntimeTurnResult = await agentResume({
        providerId: active.providerId,
        model: active.model,
        conversationId: active.sessionId,
        confirmationToken: confirmationId,
        approved: true,
      });
      renderTurnResult(result, result.continuation !== true);
      // H5: a resumed turn can itself pause on the time budget — continue it
      // transparently, same as a fresh turn.
      result = await driveContinuations(result, active.providerId, active.model, appendHint);
    } catch (error) {
      const message = humanFailure(error);
      setTurnError(message);
      pushAssistant(sprintf(__('Agent runtime failed: %s', 'wp-pfagent'), message), { errorCode: errorCode(error) });
    } finally {
      stopPolling = true;
      if (pollingTimer !== null) {
        window.clearTimeout(pollingTimer);
      }
      setProgressTrail([]);
      setBusy(false);
    }
  }

  function cancelPending(messageId: string) {
    setMessages((items) =>
      items.map((item) => (item.id === messageId ? { ...item, pending: undefined, text: `${item.text}\n\n— ${__('Action cancelled by the user.', 'wp-pfagent')}` } : item))
    );
  }

  async function handleWizardConfirm(selection: { providerId: string; model: string }) {
    if (wizardConfirmInFlightRef.current) {
      return;
    }
    wizardConfirmInFlightRef.current = true;
    try {
      // Picking a provider must NOT auto-create a conversation. We only
      // persist provider+model; sessionId is preserved (keep the conversation
      // the user already had open) or stays null (let the user explicitly
      // start a new one or pick an existing one from the list).
      const sessionId = active?.sessionId ?? null;
      const next: ActiveState = { providerId: selection.providerId, model: selection.model, sessionId };
      persistActiveState(next);
      setActive(next);
      setShowWizard(false);
      setShowSessionsInWizard(false);
    } finally {
      wizardConfirmInFlightRef.current = false;
    }
  }

  async function handleLoadSession(sessionId: string) {
    // Clear the previous conversation up front and flip on the skeleton so the
    // panel reads as "loading this conversation" rather than briefly showing
    // the old one until the fetch lands. Close the wizard immediately too —
    // the picker that triggered this load lives inside the wizard, so it must
    // come down before the await for the skeleton to be on screen during the
    // fetch (rather than hidden behind the wizard until the request resolves).
    // The composer's send button stays disabled until a provider is active;
    // the user can re-open the wizard via the Settings button to continue.
    setSessionLoading(true);
    setMessages([]);
    setShowWizard(false);
    setShowSessionsInWizard(false);
    // Adopt the target session id up front so the chat surface mounts
    // immediately (it is gated on active.sessionId) and the skeleton shows
    // while the messages are in flight — instead of staying on the picker
    // until the fetch resolves, which would hide the skeleton entirely on the
    // common "first open from the picker" path (boot always lands sessionId
    // null). Messages are filled in once the request lands below.
    const next: ActiveState = active
      ? { ...active, sessionId }
      : { providerId: '', model: '', sessionId };
    setActive(next);
    persistActiveState(next);
    try {
      const session = await getChatSession(sessionId);
      const restored = messagesFromSession(session.messages);
      setMessages(restored.length > 0 ? restored : [message('system', __('This conversation has no messages yet.', 'wp-pfagent'))]);
      setSessionLabel(session.label || __('Untitled conversation', 'wp-pfagent'));
    } catch (error) {
      setTurnError(errorMessage(error));
    } finally {
      setSessionLoading(false);
    }
  }

  async function startNewConversation() {
    try {
      const session = await createChatSession({ label: __('New conversation', 'wp-pfagent') });
      const next: ActiveState = active
        ? { ...active, sessionId: session.id }
        : { providerId: '', model: '', sessionId: session.id };
      persistActiveState(next);
      setActive(next);
      setSessionLabel(session.label || __('Untitled conversation', 'wp-pfagent'));
      setMessages([message('system', __('New conversation.', 'wp-pfagent'))]);
      setShowSessionsInWizard(false);
    } catch (error) {
      setTurnError(errorMessage(error));
    }
  }

  function startEditLabel() {
    if (!active?.sessionId || savingLabel) {
      return;
    }
    setLabelDraft(sessionLabel);
    setEditingLabel(true);
  }

  function cancelEditLabel() {
    setEditingLabel(false);
    setLabelDraft('');
  }

  async function saveEditLabel() {
    if (!active?.sessionId) {
      cancelEditLabel();
      return;
    }
    const next = labelDraft.trim();
    if (next === '' || next === sessionLabel) {
      cancelEditLabel();
      return;
    }
    setSavingLabel(true);
    try {
      const updated = await patchChatSession(active.sessionId, { label: next });
      setSessionLabel(updated.label || next);
      setEditingLabel(false);
      setLabelDraft('');
    } catch (error) {
      setTurnError(errorMessage(error));
    } finally {
      setSavingLabel(false);
    }
  }

  // Focus the input as soon as the user enters edit mode so they can type
  // immediately without an extra click.
  useEffect(() => {
    if (editingLabel) {
      labelInputRef.current?.focus();
      labelInputRef.current?.select();
    }
  }, [editingLabel]);

  function pushAssistant(text: string, extras: Partial<Pick<ChatMessage, 'executions' | 'pending' | 'errorCode'>> = {}) {
    setMessages((items) => [
      ...items,
      { ...message('assistant', text), ...extras }
    ]);
  }

  const adminUrl = config.adminUrl ?? '';
  const handleSwitchTab = useCallback((tab: IframeTab) => {
    setIframeActiveTab(tab);
  }, []);

  // Point the WordPress iframe at an activity item's native wp-admin screen and
  // bring that tab forward (the Date.now() rev forces a re-mount so re-opening
  // the same screen still reloads it).
  const showWpTarget = useCallback((target: WpTarget) => {
    const url = wpAdminUrlForTarget(wpAdminBase, target, Date.now());
    setWpUrl((current) => (current === url ? current : url));
    setShowDiagnostic(false);
    setIframeActiveTab('wordpress');
  }, [wpAdminBase]);

  return (
    <>
      <header className="pfa-fullscreen-bar" role="banner">
        <ProductSwitcher products={config.products ?? []} />
        <span className="pfa-mark" aria-hidden="true">
          {config.iconUrl ? <img src={config.iconUrl} alt="" /> : <Bot size={18} strokeWidth={2.4} />}
        </span>
        <span className="pfa-fullscreen-title">{config.name ?? 'WP-PFAgent'}<sup className="pfa-tm" aria-hidden="true">™</sup></span>
        <div className="pfa-header-right">
          {config.setyenvLogoUrl ? (
            <a
              className="pfa-setyenv-logo"
              href="https://setyenv.com"
              target="_blank"
              rel="noopener noreferrer"
              title={ __('Setyenv — setyenv.com', 'wp-pfagent') }
              aria-label={ __('Setyenv — visit setyenv.com', 'wp-pfagent') }
            >
              <img src={config.setyenvLogoUrl} alt="Setyenv" />
            </a>
          ) : null}
          <span className="pfa-fullscreen-version">v{config.version ?? ''}</span>
        </div>
      </header>
    <div className="pfa-shell">
      <aside className="pfa-chat-pane">
        <section className="pfa-active-llm">
          <div className="pfa-active-llm__head">
            <h3>{ __('Active LLM', 'wp-pfagent') }</h3>
            <div className="pfa-active-llm__actions">
              {active && active.providerId && active.model ? (
                <>
                  <button
                    type="button"
                    className="pfa-active-llm__change"
                    onClick={() => setShowWizard(true)}
                    data-testid="change-llm"
                    title={ __('Change LLM', 'wp-pfagent') }
                  >
                    { __('Change', 'wp-pfagent') }
                  </button>
                  <button
                    type="button"
                    className="pfa-active-llm__change"
                    onClick={() => void startNewConversation()}
                    data-testid="new-conversation"
                    title={ __('Start a new conversation with the active LLM', 'wp-pfagent') }
                  >
                    { __('+ New', 'wp-pfagent') }
                  </button>
                </>
              ) : (
                <button
                  type="button"
                  className="pfa-active-llm__change"
                  onClick={() => setShowWizard(true)}
                  data-testid="configure-llm"
                  title={ __('Configure LLM', 'wp-pfagent') }
                >
                  { __('Configure', 'wp-pfagent') }
                </button>
              )}
            </div>
          </div>
          {active && active.providerId && active.model ? (
            <p className="pfa-wizard__hint">
              {active.providerId} · <code>{active.model}</code>
            </p>
          ) : (
            <p className="pfa-wizard__hint">{ __('No LLM configured.', 'wp-pfagent') }</p>
          )}
        </section>

        {/* Provider + model picker AND conversation picker. The pre-part-2
            layout stacked both lists in a single view (CONVERSATION above,
            CREDENTIALS below) — restoring that here so the operator never
            loses sight of the credentials section when the wizard opens.
            Mutually exclusive only with the active-chat view. */}
        {active?.providerId && active.model && active.sessionId && !showWizard && !showSessionsInWizard ? (
          <>
            {active?.sessionId && sessionLabel ? (
              <div className="pfa-session-header">
                <button
                  type="button"
                  className="pfa-session-back"
                  onClick={() => setShowSessionsInWizard(true)}
                  aria-label={ __('Back to conversations', 'wp-pfagent') }
                  title={ __('Back to conversations', 'wp-pfagent') }
                  disabled={editingLabel}
                >
                  <ChevronLeft size={16} aria-hidden="true" />
                </button>
                {editingLabel ? (
                  <>
                    <input
                      ref={labelInputRef}
                      className="pfa-session-title-input"
                      type="text"
                      value={labelDraft}
                      onChange={(event) => setLabelDraft(event.target.value)}
                      onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                          event.preventDefault();
                          void saveEditLabel();
                        } else if (event.key === 'Escape') {
                          event.preventDefault();
                          cancelEditLabel();
                        }
                      }}
                      onBlur={() => void saveEditLabel()}
                      disabled={savingLabel}
                      maxLength={200}
                      aria-label={ __('Conversation title', 'wp-pfagent') }
                    />
                    <button
                      type="button"
                      className="pfa-session-edit"
                      onMouseDown={(event) => event.preventDefault()}
                      onClick={() => void saveEditLabel()}
                      disabled={savingLabel}
                      aria-label={ __('Save title', 'wp-pfagent') }
                      title={ __('Save title', 'wp-pfagent') }
                    >
                      <Check size={14} aria-hidden="true" />
                    </button>
                  </>
                ) : (
                  <>
                    <span className="pfa-session-title">{sessionLabel}</span>
                    <button
                      type="button"
                      className="pfa-session-edit"
                      onClick={startEditLabel}
                      aria-label={ __('Rename conversation', 'wp-pfagent') }
                      title={ __('Rename conversation', 'wp-pfagent') }
                    >
                      <Pencil size={13} aria-hidden="true" />
                    </button>
                  </>
                )}
              </div>
            ) : null}
            {activeModelContextLength && activeModelContextLength > 0 && latestPromptTokens > 0 ? (
              <div
                className="pfa-context-bar"
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={activeModelContextLength}
                aria-valuenow={Math.min(latestPromptTokens, activeModelContextLength)}
                aria-label={ __('Context window usage', 'wp-pfagent') }
                title={ sprintf(
                  /* translators: 1: tokens consumed, 2: context length, 3: percent */
                  __('%1$s / %2$s tokens (%3$d%%)', 'wp-pfagent'),
                  latestPromptTokens.toLocaleString(),
                  activeModelContextLength.toLocaleString(),
                  Math.min(100, Math.round((latestPromptTokens / activeModelContextLength) * 100))
                ) }
              >
                <div
                  className="pfa-context-bar__fill"
                  style={{ width: `${Math.min(100, (latestPromptTokens / activeModelContextLength) * 100).toFixed(2)}%` }}
                />
              </div>
            ) : null}
            <div className="pfa-messages">
              {sessionLoading && messages.length === 0 ? (
                <div className="pfa-session-skeleton" aria-hidden="true">
                  {[0, 1, 2].map((row) => (
                    <div key={row} className="pfa-skeleton-message" data-role={row % 2 === 0 ? 'assistant' : 'user'}>
                      <span className="pfa-skeleton-avatar" />
                      <span className="pfa-skeleton-lines">
                        <span className="pfa-skeleton-line" />
                        <span className="pfa-skeleton-line is-short" />
                      </span>
                    </div>
                  ))}
                </div>
              ) : null}
              {messages.filter((item) => !isEmptyAssistantRow(item)).map((item) => (
                <article key={item.id} className="pfa-message" data-role={item.role}>
                  <div className="pfa-message-avatar">{item.role === 'user' ? 'U' : <Bot size={15} />}</div>
                  <div className="pfa-message-body">
                    {item.role === 'user' ? <p>{item.text}</p> : <Markdown text={item.text} />}
                    {item.errorCode ? <small className="pfa-message-error-code"><code>{item.errorCode}</code></small> : null}
                    {item.pending ? (
                      <div className="pfa-pending-confirmation" role="alert">
                        <p className="pfa-pending-summary">
                          {pendingSummary(item.pending.toolName, item.pending.args)}
                        </p>
                        <div className="pfa-pending-actions">
                          <button type="button" className="pfa-primary-action" onClick={() => void confirmPending(item.pending!.confirmationId)} disabled={busy}>
                            { __('Confirm', 'wp-pfagent') }
                          </button>
                          <button type="button" className="pfa-secondary-action" onClick={() => cancelPending(item.id)} disabled={busy}>
                            { __('Cancel', 'wp-pfagent') }
                          </button>
                        </div>
                      </div>
                    ) : null}
                    {item.executions && item.executions.length > 0 ? (
                      <details
                        className="pfa-executions"
                        open={expandedExec.has(item.id)}
                        onToggle={(event) => {
                          const isOpen = (event.currentTarget as HTMLDetailsElement).open;
                          setExpandedExec((current) => {
                            const next = new Set(current);
                            if (isOpen) next.add(item.id);
                            else next.delete(item.id);
                            return next;
                          });
                        }}
                      >
                        {/* "1 execution(s)" is the shape a plural takes when nobody asks for
                              one. The catalogues declare six forms for Arabic and three
                              for Russian, and a parenthesised s serves none of them. */}
                        <summary>{ sprintf(_n('%d execution', '%d executions', item.executions.length, 'wp-pfagent'), item.executions.length) }</summary>
                        <ul>
                          {item.executions.map((execution, index) => (
                            <li key={index}>
                              <code>{execution.tool.name}</code>
                              <span> · {execution.durationMs}ms · {execution.status}</span>
                              {execution.diff ? <em> · { sprintf(__('diff: %s', 'wp-pfagent'), execution.diff.changeType) }</em> : null}
                              {execution.status === 'error' && execution.errorCode ? (
                                <em> · <code>{execution.errorCode}</code></em>
                              ) : null}
                            </li>
                          ))}
                        </ul>
                      </details>
                    ) : null}
                  </div>
                </article>
              ))}
              {busy && active ? (
                <article className="pfa-message pfa-message--thinking" data-role="assistant">
                  <div className="pfa-message-avatar"><Bot size={15} /></div>
                  <div className="pfa-message-body">
                    <p>
                      <span className="pfa-spinner" aria-hidden="true" />{' '}
                      {progressTrail.length > 0
                        ? progressTrail[progressTrail.length - 1]
                        : __('Thinking…', 'wp-pfagent')}
                    </p>
                  </div>
                </article>
              ) : null}
              <div ref={messagesEndRef} aria-hidden="true" />
            </div>

            {turnError ? (
              <div className="pfa-banner pfa-banner--error" role="alert">
                <strong>{ __('Error:', 'wp-pfagent') }</strong> {turnError}
              </div>
            ) : null}

            <form className="pfa-composer" onSubmit={submitChat} aria-busy={busy}>
              <input
                ref={composerInputRef}
                value={input}
                onChange={(event) => setInput(event.target.value)}
                placeholder={busy ? __('Waiting for the agent…', 'wp-pfagent') : __('Ask the agent…', 'wp-pfagent')}
                disabled={busy}
                aria-label={ __('Message to the agent', 'wp-pfagent') }
              />
              <button type="submit" disabled={busy || !input.trim()} aria-label={ __('Send', 'wp-pfagent') }>
                {busy ? <span className="pfa-spinner" aria-hidden="true" /> : <ChevronRight size={18} />}
              </button>
            </form>
          </>
        ) : (
          <>
            <ConversationPicker
              onLoadSession={(sessionId) => void handleLoadSession(sessionId)}
              refreshKey={showSessionsInWizard ? 1 : 0}
            />
            <ProviderWizard
              initialProviderId={active?.providerId}
              onConfirm={(selection) => void handleWizardConfirm(selection)}
              onCancel={active && active.providerId && active.model ? () => setShowWizard(false) : undefined}
              onCredentialDeleted={(deletedProviderId) => {
                if (active?.providerId === deletedProviderId) {
                  setActive(null);
                  setSessionLabel('');
                  setShowSessionsInWizard(false);
                  setShowWizard(false);
                }
              }}
            />
          </>
        )}
      </aside>

      <main className="pfa-iframe-pane">
        <nav className="pfa-iframe-tabs" role="tablist" aria-label={ __('Setyenv views', 'wp-pfagent') }>
          {pfwActive ? (
            <button
              type="button"
              role="tab"
              aria-selected={!showDiagnostic && iframeActiveTab === 'pfw'}
              data-active={!showDiagnostic && iframeActiveTab === 'pfw'}
              onClick={() => { setShowDiagnostic(false); handleSwitchTab('pfw'); }}
              className="pfa-iframe-tab"
            >
              { __('Workflow', 'wp-pfagent') }
            </button>
          ) : null}
          {pfmActive ? (
            <button
              type="button"
              role="tab"
              aria-selected={!showDiagnostic && iframeActiveTab === 'pfm'}
              data-active={!showDiagnostic && iframeActiveTab === 'pfm'}
              onClick={() => { setShowDiagnostic(false); handleSwitchTab('pfm'); }}
              className="pfa-iframe-tab"
            >
              { __('Management', 'wp-pfagent') }
            </button>
          ) : null}
          {/* WordPress tab: always present (WP core is always installed), shown
              after the suite tabs. Reflects the agent's direct WP-core / plugin
              actions. */}
          <WordPressTabButton
            active={!showDiagnostic && iframeActiveTab === 'wordpress'}
            onSelect={() => { setShowDiagnostic(false); handleSwitchTab('wordpress'); }}
          />
          <button
            type="button"
            role="tab"
            aria-selected={showDiagnostic}
            data-active={showDiagnostic}
            onClick={() => setShowDiagnostic(true)}
            className="pfa-iframe-tab"
          >
            { __('Diagnostic', 'wp-pfagent') }
          </button>
        </nav>
        <div className="pfa-iframe-stack">
          {pfmActive ? (
            <iframe
              className="pfa-iframe"
              data-active={!showDiagnostic && iframeActiveTab === 'pfm'}
              title={ __('Setyenv Management', 'wp-pfagent') }
              src={pfmUrl ?? config.managementDependency?.adminUrl ?? 'about:blank'}
            />
          ) : null}
          {pfwActive ? (
            <iframe
              className="pfa-iframe"
              data-active={!showDiagnostic && iframeActiveTab === 'pfw'}
              title={ __('Setyenv Workflow', 'wp-pfagent') }
              src={pfwUrl ?? config.workflowDependency?.adminUrl ?? 'about:blank'}
            />
          ) : null}
          {/* WordPress pane — shared component. */}
          <WordPressPane
            active={!showDiagnostic && iframeActiveTab === 'wordpress'}
            wpUrl={wpUrl}
            wpAdminBase={wpAdminBase}
            wpActivity={wpActivity}
            onShowTarget={showWpTarget}
          />
          {showDiagnostic ? (
            <div className="pfa-iframe-diagnostic">
              <Diagnostic />
            </div>
          ) : null}
        </div>
      </main>

    </div>
    </>
  );
}

/** Build a product-language description of a pending side-effect tool
 *  call. NEVER expose the internal tool name (pfm_apply, write_file…);
 *  always translate to what the operator will actually experience if
 *  they confirm — including the concrete resource name and whether
 *  it's a create or update. */
function pendingSummary(toolName: string, args: Record<string, unknown> | undefined): string {
  const argMap = (args ?? {}) as Record<string, unknown>;
  const path = typeof argMap.path === 'string' ? argMap.path : '';

  switch (toolName) {
    case 'pfm_apply':
      return summarizePfmApply(argMap);
    case 'pfm_delete':
      return summarizePfmDelete(argMap);
    case 'activate_workflow': {
      const wfName = workflowNameFromPath(path);
      return wfName
        ? sprintf(__('Activate workflow "%s" so it starts firing on its trigger?', 'wp-pfagent'), wfName)
        : __('Activate this workflow so it starts firing on its trigger?', 'wp-pfagent');
    }
    case 'delete_file': {
      const wfName = workflowNameFromPath(path);
      return wfName
        ? sprintf(__('Delete workflow "%s"? This cannot be undone.', 'wp-pfagent'), wfName)
        : __('Delete this workflow? This cannot be undone.', 'wp-pfagent');
    }
    case 'move_file':
      return __('Rename this workflow?', 'wp-pfagent');
    case 'write_file': {
      // Creating or overwriting a workflow file. The path encodes whether
      // it's a brand-new workflow (/workflows/new__<slug>.pfflow) or an
      // overwrite of an existing one (/workflows/<id>__<slug>.pfflow), so
      // honour the docstring's "name the resource + create vs update"
      // contract instead of falling through to the generic line.
      const wfName = workflowNameFromPath(path);
      if (wfName) {
        return /\/workflows\/new__/i.test(path)
          ? sprintf(__('Create workflow "%s"?', 'wp-pfagent'), wfName)
          : sprintf(__('Save changes to workflow "%s"?', 'wp-pfagent'), wfName);
      }
      return __('The agent is about to apply a change. Confirm to proceed.', 'wp-pfagent');
    }
    case 'edit_file': {
      const wfName = workflowNameFromPath(path);
      return wfName
        ? sprintf(__('Save changes to workflow "%s"?', 'wp-pfagent'), wfName)
        : __('The agent is about to apply a change. Confirm to proceed.', 'wp-pfagent');
    }
    default:
      return __('The agent is about to apply a change. Confirm to proceed.', 'wp-pfagent');
  }
}

/** pfm_delete({ kind, ref }) — the most dangerous thing the agent asks for, and
 *  it was the one with the least to say: it fell through to "The agent is about
 *  to apply a change. Confirm to proceed.", so a person was asked to approve a
 *  deletion without being told what would be deleted.
 *
 *  `ref` identifies the single target, and its shape is declared by the tool
 *  contract: the slug for an entity / application / role / group / event,
 *  "<application>.<module>", "<entity>.<action>", or "<entity>:<sys_id>" for one
 *  record. The sys_id itself is not shown — it says nothing to a person, and an
 *  internal id never travels to the screen. */
function summarizePfmDelete(argMap: Record<string, unknown>): string {
  const kind = typeof argMap.kind === 'string' ? argMap.kind : '';
  const ref = typeof argMap.ref === 'string' ? argMap.ref.trim() : '';

  if (kind === 'record') {
    const entityLabel = humanizeSlug(ref.split(':')[0] ?? '');
    return entityLabel
      ? sprintf(__('Delete one record from "%s"? This cannot be undone.', 'wp-pfagent'), entityLabel)
      : __('Delete this record? This cannot be undone.', 'wp-pfagent');
  }

  if (kind === 'entity') {
    const entityLabel = humanizeSlug(ref);
    // Said out loud because the contract says so and the person cannot see it:
    // dropping a table takes every record in it.
    return entityLabel
      ? sprintf(__('Delete the table "%s" and every record in it? This cannot be undone.', 'wp-pfagent'), entityLabel)
      : __('Delete this table and every record in it? This cannot be undone.', 'wp-pfagent');
  }

  // module: "<application>.<module>" · action: "<entity>.<action>" · the rest is
  // a plain slug. The tail is the thing being deleted; the head is where it
  // lives, and both are worth reading.
  const name = humanizeSlug(ref.includes('.') ? ref.split('.').slice(-1)[0] : ref);
  const kindLabel = humanizeSlug(kind);
  if (name && kindLabel) {
    return sprintf(__('Delete the %1$s "%2$s"? This cannot be undone.', 'wp-pfagent'), kindLabel.toLowerCase(), name);
  }
  if (name) {
    return sprintf(__('Delete "%s"? This cannot be undone.', 'wp-pfagent'), name);
  }
  return __('The agent is about to delete something. Confirm to proceed.', 'wp-pfagent');
}

/** pfm_apply has six shapes — { kind, payload } where payload nests
 *  the resource under its kind key. We unwrap and pull out a name +
 *  whether it's a create (no id / id<=0) or an update (id>0). */
function summarizePfmApply(argMap: Record<string, unknown>): string {
  const kind = typeof argMap.kind === 'string' ? argMap.kind : '';
  const payload = (argMap.payload ?? {}) as Record<string, unknown>;

  if (kind === 'record') {
    // The canonical shape nests the resource under its kind — `{ kind:
    // 'record', payload: { record: { entity, values } } }` — which is what the
    // bridge documents and what the model sends. This branch read
    // `payload.entity` straight, found nothing, and asked the operator to
    // approve "Create record in an entity?": a write with no table named and no
    // value shown. Every other kind below already unwraps; this one did not.
    const record = (payload.record ?? payload) as Record<string, unknown>;
    const entitySlug = typeof record.entity === 'string' ? record.entity : '';
    const entityLabel = humanizeSlug(entitySlug);
    const values = (record.values ?? {}) as Record<string, unknown>;
    const hasSysId = typeof record.sys_id === 'string' && record.sys_id !== '';
    const verb = hasSysId
      ? __('Update record in %1$s%2$s?', 'wp-pfagent')
      : __('Create record in %1$s%2$s?', 'wp-pfagent');
    const discriminator = pickRecordDiscriminator(values);
    const tail = discriminator ? ` (${discriminator})` : '';
    if (entityLabel) {
      return sprintf(verb, `"${entityLabel}"`, tail);
    }
    return sprintf(verb, __('an entity', 'wp-pfagent'), tail);
  }

  // entity / action / application / module / group / role / page —
  // payload nests under its kind key, but legacy shapes drop the
  // wrapper. Try both.
  const inner = (payload[kind] ?? payload) as Record<string, unknown>;
  const label = typeof inner.label === 'string' ? inner.label : '';
  const slug = typeof inner.slug === 'string' ? inner.slug : '';
  const name = label || humanizeSlug(slug);
  // The id decides whether this modal says "Create" or "Save changes", and
  // these keys are uuids: Number('4cb4fa90-…') is NaN, which read as "no id"
  // and made every UPDATE announce itself as a creation. Presence is the test,
  // not magnitude — 0 and -1 are the historic "absent" sentinels.
  const rawId = inner.id;
  const id = typeof rawId === 'string' ? rawId.trim() : typeof rawId === 'number' ? String(rawId) : '';
  const isCreate = id === '' || id === '0' || id === '-1';
  const kindLabel = pfmKindLabel(kind);

  if (name) {
    return isCreate
      ? sprintf(__('Create %1$s "%2$s"?', 'wp-pfagent'), kindLabel, name)
      : sprintf(__('Save changes to %1$s "%2$s"?', 'wp-pfagent'), kindLabel, name);
  }
  return isCreate
    ? sprintf(__('Create a new %s?', 'wp-pfagent'), kindLabel)
    : sprintf(__('Save changes to a %s?', 'wp-pfagent'), kindLabel);
}

/** Pick a short, human-meaningful value from a record's values map
 *  ("Email subject · Weekly summary…"). Falls back to the first
 *  scalar value found. */
function pickRecordDiscriminator(values: Record<string, unknown>): string {
  // Prefer common identifying keys.
  for (const key of ['name', 'titulo', 'title', 'label', 'clave', 'code', 'id_correlativo']) {
    const v = values[key];
    if (typeof v === 'string' && v.trim() !== '') return v.length > 60 ? `${v.slice(0, 57)}…` : v;
    if (typeof v === 'number') return String(v);
  }
  // Fallback: first scalar.
  for (const v of Object.values(values)) {
    if (typeof v === 'string' && v.trim() !== '') return v.length > 60 ? `${v.slice(0, 57)}…` : v;
    if (typeof v === 'number') return String(v);
  }
  return '';
}

function humanizeSlug(slug: string): string {
  const raw = (slug ?? '').trim();
  if (raw === '') return '';
  const spaced = raw.replace(/[_-]+/g, ' ').toLowerCase();
  return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

function pfmKindLabel(kind: string): string {
  switch (kind) {
    case 'entity':      return __('entity', 'wp-pfagent');
    case 'record':      return __('record', 'wp-pfagent');
    case 'action':      return __('action', 'wp-pfagent');
    case 'application': return __('application', 'wp-pfagent');
    case 'module':      return __('module', 'wp-pfagent');
    case 'group':       return __('group', 'wp-pfagent');
    case 'role':        return __('role', 'wp-pfagent');
    case 'page':        return __('page', 'wp-pfagent');
    default:            return __('resource', 'wp-pfagent');
  }
}

function workflowNameFromPath(path: string): string {
  // /workflows/new__<slug>.pfflow or /workflows/<id>__<slug>.pfflow
  const m = path.match(/\/workflows\/(?:new|\d+)__([a-z0-9_\-]+)\.pfflow$/i);
  if (!m) return '';
  return m[1].replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

// Translate an internal tool name to a generalist, customer-facing
// progress line in the operator's locale. The hard rule is: never
// expose the tool name, never paste source paths, never name the
// compiler — describe what is happening in product language. WordPress'
// load_plugin_textdomain() reads the user's locale per REST call
// (see wp-pfagent.php determine_locale filter), so __() resolves
// against the right .mo file. New tools should land here in en + the
// three shipped locales (es, zh, ja).
function progressHintForTool(tool: string): string {
  switch (tool) {
    case 'list_files':
      return __('Exploring the workspace…', 'wp-pfagent');
    case 'read_file':
      return __('Reading the reference library…', 'wp-pfagent');
    case 'write_file':
    case 'edit_file':
      return __('Implementing the workflow logic…', 'wp-pfagent');
    case 'move_file':
      return __('Reorganising files…', 'wp-pfagent');
    case 'delete_file':
      return __('Removing a file…', 'wp-pfagent');
    case 'pfm_get_contract':
      return __('Looking up the data model…', 'wp-pfagent');
    case 'pfm_list':
      return __('Listing resources…', 'wp-pfagent');
    case 'pfm_get':
      return __('Loading a resource…', 'wp-pfagent');
    case 'pfm_apply':
      return __('Applying changes…', 'wp-pfagent');
    default:
      return __('Working on it…', 'wp-pfagent');
  }
}

function progressHintForTraceKind(kind: string): string {
  switch (kind) {
    case 'llm_round':
      return __('Thinking…', 'wp-pfagent');
    case 'compaction_applied':
      return __('Summarising older context…', 'wp-pfagent');
    default:
      return __('Working on it…', 'wp-pfagent');
  }
}

// Poll /agent-runtime/progress every 2 s while the turn is in flight.
// On every poll:
//   - newest tool fires the hint (what the agent is doing right now);
//   - newest tool ALSO drives the iframe pane in real time: a
//     pfm_get / pfm_apply jumps to the management tab focused on
//     kind/ref, a write_file / activate_workflow jumps to the
//     workflow tab focused on workflowId. The operator sees the
//     iframe dance with the agent's activity, not a single jump at
//     end-of-turn.
//   - newly persisted assistant rows fire pushNarration so each
//     mid-loop "Voy a hacer X / Ahora creo Y" surfaces as its own
//     chat bubble live, not as a burst at end-of-turn. The caller
//     dedupes against the end-of-turn assistantTexts payload via
//     the ordinal carried on each narration.
function startProgressPolling(
  conversationId: string,
  cancelled: () => boolean,
  setHint: (hint: string) => void,
  setFocusFromTool: (tool: AgentRuntimeProgressTool) => void,
  pushNarration: (narrations: AgentRuntimeProgressNarration[]) => void,
  /** Cursors the caller already learned via a pre-fetch. Pass 0 / -1
   *  to start from scratch (legacy behaviour). Used by runTurn /
   *  confirmPending to skip every assistant row that existed
   *  BEFORE the turn started — without this seed the first poll
   *  re-pushed every historical narration as a fresh bubble. */
  initialCursors: { sinceToolCallSeq: number; sinceTraceSeq: number; sinceMessageOrdinal: number } = {
    sinceToolCallSeq: 0, sinceTraceSeq: 0, sinceMessageOrdinal: -1,
  }
): number {
  let sinceToolCallSeq = initialCursors.sinceToolCallSeq;
  let sinceTraceSeq = initialCursors.sinceTraceSeq;
  let sinceMessageOrdinal = initialCursors.sinceMessageOrdinal;
  const tick = async (): Promise<void> => {
    if (cancelled()) {
      return;
    }
    try {
      const progress = await agentProgress({ conversationId, sinceToolCallSeq, sinceTraceSeq, sinceMessageOrdinal });
      if (cancelled()) {
        return;
      }
      const lastTool = progress.tools.length > 0 ? progress.tools[progress.tools.length - 1] : null;
      const lastTrace = progress.traces.length > 0 ? progress.traces[progress.traces.length - 1] : null;
      if (lastTool) {
        setHint(progressHintForTool(lastTool.tool));
        setFocusFromTool(lastTool);
      } else if (lastTrace) {
        setHint(progressHintForTraceKind(lastTrace.kind));
      }
      if (Array.isArray(progress.assistantTexts) && progress.assistantTexts.length > 0) {
        pushNarration(progress.assistantTexts);
      }
      sinceToolCallSeq = progress.lastToolCallSeq;
      sinceTraceSeq = progress.lastTraceSeq;
      // lastMessageOrdinal advances past empty-content rows too, so
      // we don't refetch them on every poll.
      if (typeof progress.lastMessageOrdinal === 'number') {
        sinceMessageOrdinal = progress.lastMessageOrdinal;
      }
    } catch {
      // Progress is best-effort; a failed poll never breaks the turn.
    }
    if (!cancelled()) {
      window.setTimeout(tick, 2000);
    }
  };
  return window.setTimeout(tick, 800);
}

/** Compute the iframe target from a single progress tool entry —
 *  the polling counterpart of inferPreviewTarget (which works off
 *  the end-of-turn executions list). Returns null for tools that
 *  don't point at anything visible (pfm_get_contract has no kind
 *  to focus on; list_files etc. don't anchor to a specific
 *  workflow). */
function inferPreviewTargetFromProgressTool(
  tool: AgentRuntimeProgressTool,
  pfmAdminUrl: string | null,
  pfwAdminUrl: string | null,
  wpAdminUrl: string | null,
): { tab: IframeTab; url: string } | null {
  const name = tool.tool;
  const focus = tool.focus ?? {};
  // Transversal WordPress family: the runtime stamps focus.wpTarget on wp_*
  // tools; map it to a native wp-admin screen live. Additive; the workflowId /
  // kind-ref branches below are untouched.
  if (isWpTool(name) && wpAdminUrl && focus.wpTarget) {
    return {
      tab: 'wordpress',
      url: wpAdminUrlForTarget(wpAdminUrl, focus.wpTarget as WpTarget, tool.seq),
    };
  }
  if (name === 'pfm_get' || name === 'pfm_apply' || name === 'pfm_list') {
    // A business_rule apply births a paired workflow; when the server surfaces
    // its id on the focus payload, focus the WORKFLOW tab on it live (mirrors
    // the end-of-turn path). Takes precedence over the pfm target.
    const wfId = workflowIdOf(focus.workflowId);
    if (wfId !== null && pfwAdminUrl) {
      const params = new URLSearchParams();
      params.set('pfa_preview', '1');
      params.set('workflow_id', wfId);
      params.set('_rev', String(tool.seq));
      return { tab: 'pfw', url: appendQueryString(pfwAdminUrl, params) };
    }
    if (!pfmAdminUrl) return null;
    const kind = focus.kind ?? '';
    if (!kind) return null;
    const ref = focus.ref ?? '';
    const params = new URLSearchParams();
    params.set('pfa_preview', '1');
    params.set('kind', kind);
    if (ref) params.set('ref', ref);
    // F3: same per-tool refresh signal the PFW branch uses. Re-editing the SAME
    // resource twice produces the same kind+ref; without a changing _rev the
    // iframe src is byte-identical and the management SPA never re-mounts, so it
    // keeps showing the pre-edit state. tool.seq is stable across polls of one
    // tool but distinct between two edits → the second edit forces a refresh.
    params.set('_rev', String(tool.seq));
    return { tab: 'pfm', url: appendQueryString(pfmAdminUrl, params) };
  }
  if (
    (name === 'write_file' || name === 'edit_file' || name === 'move_file' || name === 'delete_file' || name === 'activate_workflow' || name === 'create_variable') &&
    pfwAdminUrl
  ) {
    const wid = workflowIdOf(focus.workflowId);
    if (wid === null) return null;
    const params = new URLSearchParams();
    params.set('pfa_preview', '1');
    params.set('workflow_id', wid);
    // Per-tool-call refresh signal. _rev is the DB id of the tool row
    // — stable across polls of the SAME tool (so a write_file at
    // tool.seq=42 produces _rev=42 on every poll until a NEW tool
    // lands), but different between consecutive write_file /
    // edit_file / create_variable / activate_workflow runs. The
    // iframe re-mounts only when _rev changes, so:
    //   - polling a long-running same tool: no churn (the URL is
    //     identical across polls).
    //   - agent runs write_file, then create_variable on the same
    //     workflow: two distinct tool.ids → two distinct URLs →
    //     editor re-mounts with the fresh graph + the new variable
    //     visible. This fixes the "added the variable, terminated
    //     the workflow, but the editor still shows the version
    //     loaded at write_file" bug the operator reported.
    params.set('_rev', String(tool.seq));
    return { tab: 'pfw', url: appendQueryString(pfwAdminUrl, params) };
  }
  return null;
}

// Infer which iframe pane should jump into focus after a turn, based on
// what tools the agent actually ran. Side-effecting tools win (a
// pfm_apply or write_file/edit_file/activate_workflow is what the
// operator wants to verify); if none ran, the last successful read
// wins so the user lands on the resource the agent was inspecting.
// Returns null when nothing focusable happened (chat-only turn) or
// when the target plugin is not active.
function inferPreviewTarget(
  executions: AgentRuntimeExecution[],
  pfmAdminUrl: string | null,
  pfwAdminUrl: string | null,
  wpAdminUrl: string | null,
): { tab: IframeTab; url: string } | null {
  if (executions.length === 0) {
    return null;
  }
  // Side-effecting tools win (the operator wants to verify a write) - across
  // ALL three families (pfm/pfw suite + transversal WP). If none ran, the last
  // successful read wins.
  const sideEffectTools = new Set([
    'pfm_apply', 'write_file', 'edit_file', 'move_file', 'delete_file', 'activate_workflow',
    'wp_post_create', 'wp_post_update', 'wp_post_trash', 'wp_term_create', 'wp_term_assign',
    'wp_user_create', 'wp_user_update', 'wp_comment_moderate', 'wp_option_set', 'wc_order_note', 'seo_set',
  ]);
  const reversed = [...executions].reverse();
  const lastSide = reversed.find((e) => e.status === 'success' && sideEffectTools.has(e.tool.name));
  const last = lastSide ?? reversed.find((e) => e.status === 'success');
  if (!last) {
    return null;
  }
  return buildPreviewTargetFromExecution(last, pfmAdminUrl, pfwAdminUrl, wpAdminUrl);
}

function buildPreviewTargetFromExecution(
  exec: AgentRuntimeExecution,
  pfmAdminUrl: string | null,
  pfwAdminUrl: string | null,
  wpAdminUrl: string | null,
): { tab: IframeTab; url: string } | null {
  const tool = exec.tool.name;
  const args = (exec.tool.arguments ?? {}) as Record<string, unknown>;
  const result = (exec.result ?? {}) as Record<string, unknown>;

  // Transversal WordPress family (wp_*, wc_*, seo_*, forms_*): map the execution to
  // a native wp-admin screen. Additive - sits alongside the pfm/pfw branches,
  // never intercepts them.
  if (isWpTool(tool) && wpAdminUrl) {
    const target = describeWpExecution(exec)?.target ?? null;
    if (!target) return null;
    const rev = Date.parse(exec.startedAt) || Date.now();
    return { tab: 'wordpress', url: wpAdminUrlForTarget(wpAdminUrl, target, rev) };
  }

  // PFM family: kind + ref straight from args (pfm_get / pfm_apply
  // declare both). For pfm_apply the ref lives inside payload with a
  // kind-specific shape — record carries the entity slug as a string
  // and an optional sys_id; entity / action / application / module /
  // group / role / page carry payload[kind].slug. The pfm SPA needs
  // both kind AND ref to navigate (it falls back to its landing page
  // when ref is missing), so without this extraction the operator
  // saw "Bienvenido" no matter which entity the agent touched.
  if (tool === 'pfm_get' || tool === 'pfm_apply' || tool === 'pfm_list') {
    // A business_rule apply births/edits a PAIRED workflow. Focus the WORKFLOW
    // tab on it (takes precedence over the pfm target) so "create a business
    // rule / flow" actually paints the workflow instead of leaving the workflow
    // tab on "Waiting for the agent" — the operator's #1 visible complaint.
    const brWfId = extractBusinessRuleWorkflowId(result);
    if (brWfId && pfwAdminUrl) {
      const params = new URLSearchParams();
      params.set('pfa_preview', '1');
      params.set('workflow_id', String(brWfId));
      const rev = Date.parse(exec.startedAt) || Date.now();
      params.set('_rev', String(rev));
      return { tab: 'pfw', url: appendQueryString(pfwAdminUrl, params) };
    }
    if (!pfmAdminUrl) return null;
    const kind = typeof args.kind === 'string' ? args.kind : '';
    let ref: string | null = typeof args.ref === 'string' ? args.ref : null;
    if (!ref && tool === 'pfm_apply') {
      ref = extractPfmApplyRef(kind, args.payload);
    }
    const params = new URLSearchParams();
    params.set('pfa_preview', '1');
    if (kind) params.set('kind', kind);
    if (ref) params.set('ref', ref);
    // F3: refresh signal so re-editing the SAME resource re-mounts the iframe.
    // Same kind+ref twice → identical src → the management SPA keeps the pre-edit
    // state; a changing _rev (this call's start time) forces the fresh load.
    // Mirrors the PFW branch below.
    const rev = Date.parse(exec.startedAt) || Date.now();
    params.set('_rev', String(rev));
    return { tab: 'pfm', url: appendQueryString(pfmAdminUrl, params) };
  }

  // PFW family: write/edit/move/delete/activate carry workflowId in
  // the result (the bridge stamps it on every success).
  if (tool === 'write_file' || tool === 'edit_file' || tool === 'move_file' || tool === 'delete_file' || tool === 'activate_workflow') {
    if (!pfwAdminUrl) return null;
    const wfId = extractWorkflowIdFromResult(result);
    if (!wfId) return null;
    const params = new URLSearchParams();
    params.set('pfa_preview', '1');
    params.set('workflow_id', String(wfId));
    // Per-call refresh signal. Mirrors the polling path's _rev (see
    // inferPreviewTargetFromProgressTool) but uses the execution's
    // startedAt timestamp as the cache key — exec rows don't carry a
    // stable id we can reach, but startedAt is unique per call (the
    // runtime stamps it server-side at tool dispatch). Iframe re-
    // mounts when this URL changes between turns; same workflow ID
    // alone is NOT enough for the editor to know its graph data
    // changed.
    const rev = Date.parse(exec.startedAt) || Date.now();
    params.set('_rev', String(rev));
    return { tab: 'pfw', url: appendQueryString(pfwAdminUrl, params) };
  }

  return null;
}

/** Pull a meaningful `ref` out of a pfm_apply payload so the pfm iframe
 *  can navigate to the entity / record / page the agent just touched.
 *  Mirrors the PHP-side extraction in RestApi::agent_progress so the
 *  live-polling stream and the end-of-turn execution path produce the
 *  same URL string (and therefore the iframe doesn't churn between
 *  two different focuses for the same call). Returns null when nothing
 *  navigable is in the payload. */
function extractPfmApplyRef(kind: string, payloadIn: unknown): string | null {
  if (!payloadIn || typeof payloadIn !== 'object' || kind === '') return null;
  const payload = payloadIn as Record<string, unknown>;

  if (kind === 'record') {
    const eslug = typeof payload.entity === 'string' ? payload.entity : '';
    const sysIdRaw =
      typeof payload.sys_id === 'string' || typeof payload.sys_id === 'number'
        ? String(payload.sys_id)
        : typeof payload.id === 'string' || typeof payload.id === 'number'
          ? String(payload.id)
          : '';
    if (eslug !== '' && sysIdRaw !== '') return `${eslug}:${sysIdRaw}`;
    // No sys_id yet (create record): point the tab at the entity's
    // list so the operator at least lands somewhere related.
    if (eslug !== '') return eslug;
    return null;
  }

  // entity / action / application / module / group / role / page —
  // payload[kind].slug with a legacy bare-payload.slug fallback.
  const innerCandidate = payload[kind];
  const inner =
    innerCandidate && typeof innerCandidate === 'object' && !Array.isArray(innerCandidate)
      ? (innerCandidate as Record<string, unknown>)
      : payload;
  const slug = inner.slug;
  if (typeof slug === 'string' && slug !== '') return slug;
  return null;
}

function extractWorkflowIdFromResult(result: Record<string, unknown>): string | null {
  // Most VFS calls return { workflowId } at the top level (per
  // WorkflowVfsBridge wrappers). delete_file does not — it returns
  // { deleted: true } only, in which case we have no id to focus on.
  return workflowIdOf(result.workflowId);
}

/** A workflow id as the id it is.
 *
 *  PFW hands these out as uuids and the payload contract carries EITHER shape
 *  (a legacy install still sends an int). This used to demand a number, so on
 *  every current install the answer was "no id" — no error, just a workflow
 *  preview that never opened and a tab that stayed on "Waiting for the agent".
 *  0 and -1 are the historic "absent" sentinels and still mean absent. */
function workflowIdOf(raw: unknown): string | null {
  if (typeof raw === 'number') {
    return raw > 0 ? String(raw) : null;
  }
  if (typeof raw !== 'string') {
    return null;
  }
  const id = raw.trim();
  return id === '' || id === '0' || id === '-1' ? null : id;
}

/** A pfm_apply on kind=business_rule births (or re-reads) a PAIRED workflow —
 *  its id lives at result.content.business_rule.workflow_id (snake_case, nested
 *  under the tool envelope). Pull it so the workflow tab can focus that workflow
 *  the moment the agent creates the rule, instead of the tab sitting on
 *  "Waiting for the agent". Returns null for every other pfm apply. */
function extractBusinessRuleWorkflowId(result: Record<string, unknown>): string | null {
  const content = result.content;
  if (!content || typeof content !== 'object') return null;
  const br = (content as Record<string, unknown>).business_rule;
  if (!br || typeof br !== 'object') return null;
  return workflowIdOf((br as Record<string, unknown>).workflow_id);
}

function appendQueryString(base: string, params: URLSearchParams): string {
  const hasQuery = base.includes('?');
  const sep = hasQuery ? '&' : '?';
  return `${base}${sep}${params.toString()}`;
}

/** Make sure any URL handed to an embedded iframe carries the
 *  pfa_preview=1 flag so the sister plugin renders in focus mode.
 *  Safe to call on a URL that already has it. */
function ensurePfaPreviewParam(url: string | null): string | null {
  if (!url) return null;
  if (url.includes('pfa_preview=1')) return url;
  const sep = url.includes('?') ? '&' : '?';
  return `${url}${sep}pfa_preview=1`;
}

/** Splice a pending-confirmation block into the most recent assistant
 *  message (preserving the executions seen so far) instead of pushing
 *  a fresh empty bubble. Falls back to creating a minimal placeholder
 *  bubble when there is no prior assistant message — shouldn't happen
 *  in normal flow because a pause always follows at least one tool
 *  round, but we cover it for safety. */
function attachPendingToLastAssistant(
  items: ChatMessage[],
  pending: NonNullable<ChatMessage['pending']>,
  executions: AgentRuntimeExecution[] | undefined
): ChatMessage[] {
  for (let i = items.length - 1; i >= 0; i--) {
    if (items[i].role === 'assistant') {
      const next = [...items];
      const prevExec = next[i].executions ?? [];
      const merged = executions && executions.length > 0 ? executions : prevExec;
      next[i] = { ...next[i], pending, executions: merged };
      return next;
    }
  }
  return [...items, { ...message('assistant', ''), pending, executions }];
}

/** Attach the end-of-turn decoration (executions list, pending modal,
 *  errorCode) to whichever assistant bubble was most recently rendered.
 *  Used when every narration this turn streamed live via the polling
 *  loop — there are no new bubbles to push, but the executions list
 *  and the confirmation modal still need a host. Unlike
 *  attachPendingToLastAssistant this is a no-op when no prior
 *  assistant bubble exists (the caller only invokes us when
 *  livePushedAny is true, so one always does). */
function attachDecorationToLastAssistant(
  items: ChatMessage[],
  decoration: {
    executions?: AgentRuntimeExecution[];
    pending?: ChatMessage['pending'];
    errorCode?: string;
  }
): ChatMessage[] {
  for (let i = items.length - 1; i >= 0; i--) {
    if (items[i].role === 'assistant') {
      const next = [...items];
      const prev = next[i];
      next[i] = {
        ...prev,
        // Merge: existing executions are kept if the new payload is
        // empty (the polling stream may have decorated a bubble
        // already), otherwise the new payload wins.
        executions:
          decoration.executions && decoration.executions.length > 0
            ? decoration.executions
            : prev.executions,
        pending: decoration.pending ?? prev.pending,
        errorCode: decoration.errorCode ?? prev.errorCode,
      };
      return next;
    }
  }
  return items;
}

function initialBootMessages(): ChatMessage[] {
  return [
    message(
      'system',
      __('Pick an LLM provider to get started. Your credentials live encrypted inside the WordPress store.', 'wp-pfagent')
    )
  ];
}

function messagesFromSession(sessionMessages: ChatSessionMessage[]): ChatMessage[] {
  // DISPLAY-ONLY filter — mirrors what the LIVE transcript shows (user +
  // assistant turns), never what goes to the LLM. Every persisted 'system'
  // message is the system prompt at ordinal 1 (context/priming); the live view
  // never renders it, so a reopened session must not either — otherwise the
  // prompt "leaks" as a giant first bubble and throws the whole transcript off.
  // The context the Loop assembles and sends is untouched: this only decides
  // what the frontend paints from a loaded session. Ephemeral 'system' UI
  // notices ("New conversation.", "Session N is empty.") are added directly via
  // message('system', …), not through here, so they still show.
  return sessionMessages
    .filter((entry) => entry.role === 'user' || entry.role === 'assistant')
    .map((entry, index) => ({
      id: `${entry.at ?? Date.now()}-${index}`,
      role: entry.role,
      text: entry.content,
      at: entry.at ?? new Date().toISOString(),
      // F4: carry executions through on reload so the "N execution(s)" panel
      // re-renders. Undefined on payloads without them (older backend) → no panel.
      executions: Array.isArray(entry.executions) && entry.executions.length > 0 ? entry.executions : undefined,
    }));
}

function message(role: ChatMessage['role'], text: string): ChatMessage {
  return {
    id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
    role,
    text,
    at: new Date().toISOString()
  };
}

/** An assistant row with nothing to show — no text, no pending confirmation, no
 *  executions, no error. This is what a confirmation bubble collapses to after
 *  the operator confirms (the pending is consumed and its result renders in the
 *  turn's answer bubble), so we drop it from the render instead of leaving an
 *  empty gap. User/system rows always render. */
function isEmptyAssistantRow(item: ChatMessage): boolean {
  return (
    item.role === 'assistant' &&
    (item.text ?? '').trim() === '' &&
    !item.pending &&
    (!item.executions || item.executions.length === 0) &&
    !item.errorCode
  );
}

function StatusDot({ label, status }: { label: string; status: ApiCheckStatus }) {
  return (
    <span className="pfa-status-dot" data-status={status}>
      {label}: {status}
    </span>
  );
}

function settledError<T>(result: PromiseSettledResult<T>): string {
  if (result.status === 'fulfilled') {
    return '';
  }

  return errorMessage(result.reason);
}

function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : __('Unexpected error.', 'wp-pfagent');
}

/**
 * What to tell a person when a turn does not come back.
 *
 * The chat recovers correctly from all three failures — the bubble clears and
 * the composer comes back — but it repeated the machine's own words: "Failed to
 * fetch", which is the browser's phrase for a dead connection, and "WP PFAgent
 * API POST agent-runtime/turn-v2 returned an invalid empty response", which puts
 * a method and an endpoint in front of someone who asked how many tasks they
 * have. The two failures a person can act on are named here; anything the server
 * words itself travels as it is, because that message was written to be read.
 * The code is not dropped: it rides on the message as errorCode, where the
 * diagnostic already reads it.
 */
function humanFailure(error: unknown): string {
  const code = errorCode(error);
  const status = error && typeof error === 'object' && 'status' in error
    ? Number((error as { status?: unknown }).status)
    : NaN;

  if (code === 'empty_response') {
    return __('The answer did not arrive complete. Ask again.', 'wp-pfagent');
  }

  // A request that never reached the server: fetch rejects with a TypeError and
  // no status at all, and an aborted one carries status 0.
  const raw = errorMessage(error);
  if (code === 'aborted' || status === 0 || /failed to fetch|networkerror|load failed/i.test(raw)) {
    return __('The assistant could not be reached. Check the connection and ask again.', 'wp-pfagent');
  }

  return raw;
}

function errorCode(error: unknown): string | undefined {
  if (error && typeof error === 'object' && 'code' in error) {
    const value = (error as { code?: unknown }).code;
    if (typeof value === 'string' && value !== '') {
      return value;
    }
  }

  return undefined;
}

function statusFallback(status: string): string {
  switch (status) {
    case 'completed':
      return __('Done.', 'wp-pfagent');
    case 'needs_confirmation':
      return __('This action requires confirmation.', 'wp-pfagent');
    case 'rejected':
      return __('The runtime rejected this call.', 'wp-pfagent');
    case 'completed_with_response_error':
      // Do NOT claim an action ran: this status also covers a turn that died
      // on its very first call to the provider, where nothing ran at all.
      return __('The AI provider did not return a response.', 'wp-pfagent');
    case 'paused':
      return __('Still working…', 'wp-pfagent');
    default:
      return __('No response from the runtime.', 'wp-pfagent');
  }
}
